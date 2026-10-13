#!/usr/bin/env python3
"""Turn `ibd2sdi` JSON dumps of MySQL 8.0 .ibd files into CREATE TABLE statements.

Each MySQL 8 tablespace embeds its data-dictionary entry (SDI). This script
rebuilds the DDL so the table can be created empty and the original .ibd
attached with ALTER TABLE ... IMPORT TABLESPACE.

Usage:
  sdi2ddl.py --sdi-dir import/sdi --out import/schema.sql [--ddl-dir import/ddl]
             [--collations import/collations.tsv]
"""
import argparse
import glob
import json
import os
import re
import sys

# collation id -> (collation, charset, bytes per char). Every id seen in the prod SDIs is here;
# `make collations` dumps the full list from the running server for anything else.
COLLATIONS = {
    8: ("latin1_swedish_ci", "latin1", 1),
    11: ("ascii_general_ci", "ascii", 1),
    33: ("utf8mb3_general_ci", "utf8mb3", 3),
    45: ("utf8mb4_general_ci", "utf8mb4", 4),
    46: ("utf8mb4_bin", "utf8mb4", 4),
    63: ("binary", "binary", 1),
    65: ("ascii_bin", "ascii", 1),
    83: ("utf8mb3_bin", "utf8mb3", 3),
    224: ("utf8mb4_unicode_ci", "utf8mb4", 4),
    255: ("utf8mb4_0900_ai_ci", "utf8mb4", 4),
}
CHARSET_MAXLEN = {"latin1": 1, "ascii": 1, "binary": 1, "utf8mb3": 3, "utf8": 3,
                  "utf8mb4": 4, "utf16": 4, "utf32": 4, "ucs2": 2}
ROW_FORMATS = {1: "FIXED", 2: "DYNAMIC", 3: "COMPRESSED", 4: "REDUNDANT", 5: "COMPACT"}
INDEX_KINDS = {1: "PRIMARY KEY", 2: "UNIQUE KEY", 3: "KEY", 4: "FULLTEXT KEY", 5: "SPATIAL KEY"}
WHOLE_COLUMN = 4294967295

STRING_TYPE = re.compile(r"^(var)?char|^(tiny|medium|long)?text|^enum|^set")
NO_LITERAL_DEFAULT = re.compile(r"^(tiny|medium|long)?blob|^(tiny|medium|long)?text|^json|^geometry")
PREFIXABLE = re.compile(r"^(var)?char|^(var)?binary|^(tiny|medium|long)?text|^(tiny|medium|long)?blob")


def load_collations(path):
    with open(path) as fh:
        for line in fh:
            parts = line.rstrip("\n").split("\t")
            if len(parts) < 3:
                continue
            cid, name, charset = parts[0], parts[1], parts[2]
            COLLATIONS[int(cid)] = (name, charset, CHARSET_MAXLEN.get(charset, 4))


def collation(cid):
    if cid not in COLLATIONS:
        sys.exit(f"unknown collation id {cid}; run `make collations` and pass --collations")
    return COLLATIONS[cid]


def quote_ident(name):
    return "`" + name.replace("`", "``") + "`"


def quote_literal(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


def column_sql(col, table_collation_id):
    ctype = col["column_type_utf8"]
    parts = [quote_ident(col["name"]), ctype]
    if STRING_TYPE.match(ctype) and (col["is_explicit_collation"] or col["collation_id"] != table_collation_id):
        name, charset, _ = collation(col["collation_id"])
        parts += [f"CHARACTER SET {charset}", f"COLLATE {name}"]
    if col.get("generation_expression_utf8"):
        kind = "VIRTUAL" if col.get("is_virtual") else "STORED"
        parts.append(f"GENERATED ALWAYS AS ({col['generation_expression_utf8']}) {kind}")
    parts.append("NULL" if col["is_nullable"] else "NOT NULL")
    if col["is_auto_increment"]:
        parts.append("AUTO_INCREMENT")
    elif col.get("default_option"):
        parts.append("DEFAULT " + col["default_option"])
    elif not col["has_no_default"]:
        if col["default_value_null"]:
            if col["is_nullable"]:
                parts.append("DEFAULT NULL")
        elif not col.get("default_value_utf8_null") and not NO_LITERAL_DEFAULT.match(ctype):
            parts.append("DEFAULT " + quote_literal(col["default_value_utf8"]))
    if col.get("update_option"):
        parts.append("ON UPDATE " + col["update_option"])
    if col["comment"]:
        parts.append("COMMENT " + quote_literal(col["comment"]))
    return " ".join(parts)


def index_sql(index, columns):
    elements = []
    for element in index["elements"]:
        if element["hidden"]:  # implicit trailing primary-key columns
            continue
        col = columns[element["column_opx"]]  # opx indexes the unfiltered column list
        ctype = col["column_type_utf8"]
        part = quote_ident(col["name"])
        length = element["length"]
        if PREFIXABLE.match(ctype) and length != WHOLE_COLUMN and length < col["char_length"]:
            _, _, maxlen = collation(col["collation_id"])
            part += f"({length // maxlen})"
        if element.get("order") == 3:
            part += " DESC"
        elements.append(part)
    kind = INDEX_KINDS[index["type"]]
    head = kind if index["type"] == 1 else f"{kind} {quote_ident(index['name'])}"
    sql = f"{head} ({', '.join(elements)})"
    if not index.get("is_visible", True):
        sql += " INVISIBLE"
    if index["comment"]:
        sql += " COMMENT " + quote_literal(index["comment"])
    return sql


def instant_ddl_warning(dd):
    for col in dd["columns"]:
        private = col.get("se_private_data", "")
        if re.search(r"version_added|version_dropped", private):
            return (f"-- WARNING: {dd['name']} has INSTANT add/drop metadata "
                    f"({col['name']}: {private}); import without .cfg may fail\n")
    return ""


def table_sql(dd):
    columns = dd["columns"]
    visible = sorted((c for c in columns if c["hidden"] == 1), key=lambda c: c["ordinal_position"])
    lines = [column_sql(c, dd["collation_id"]) for c in visible]
    lines += [index_sql(i, columns) for i in dd["indexes"] if not i.get("hidden")]

    options = ["ENGINE=InnoDB"]
    autoinc = re.search(r"autoinc=(\d+)", dd.get("se_private_data", ""))
    if autoinc:
        options.append(f"AUTO_INCREMENT={autoinc.group(1)}")
    name, charset, _ = collation(dd["collation_id"])
    options += [f"DEFAULT CHARSET={charset}", f"COLLATE={name}"]
    if dd["row_format"] in ROW_FORMATS:
        options.append("ROW_FORMAT=" + ROW_FORMATS[dd["row_format"]])
    key_block = re.search(r"key_block_size=(\d+)", dd.get("options", ""))
    if key_block and key_block.group(1) != "0":
        options.append("KEY_BLOCK_SIZE=" + key_block.group(1))
    if dd["comment"]:
        options.append("COMMENT=" + quote_literal(dd["comment"]))

    body = ",\n  ".join(lines)
    return f"CREATE TABLE IF NOT EXISTS {quote_ident(dd['name'])} (\n  {body}\n) {' '.join(options)};\n"


def table_object(path):
    with open(path) as fh:
        entries = json.load(fh)
    for entry in entries:
        if isinstance(entry, dict) and entry.get("object", {}).get("dd_object_type") == "Table":
            return entry["object"]["dd_object"]
    sys.exit(f"{path}: no Table object in SDI")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--sdi-dir", required=True)
    parser.add_argument("--out", required=True)
    parser.add_argument("--ddl-dir")
    parser.add_argument("--collations")
    args = parser.parse_args()

    if args.collations:
        load_collations(args.collations)
    if args.ddl_dir:
        os.makedirs(args.ddl_dir, exist_ok=True)

    statements = []
    for path in sorted(glob.glob(os.path.join(args.sdi_dir, "*.json"))):
        dd = table_object(path)
        sql = instant_ddl_warning(dd) + table_sql(dd)
        statements.append(sql)
        if args.ddl_dir:
            with open(os.path.join(args.ddl_dir, dd["name"] + ".sql"), "w") as fh:
                fh.write(sql)

    header = "SET NAMES utf8mb4;\nSET SESSION foreign_key_checks=0;\nSET SESSION sql_mode='NO_ENGINE_SUBSTITUTION';\n\n"
    with open(args.out, "w") as fh:
        fh.write(header + "\n".join(statements))
    print(f"wrote {args.out}: {len(statements)} tables", file=sys.stderr)


if __name__ == "__main__":
    main()
