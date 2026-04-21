#!/usr/bin/env python
# coding: utf-8

# In[1]:


# from inside the notebook
import sys
get_ipython().system('{sys.executable} -m pip install --upgrade pyarrow')


# In[2]:


# ============================================================
# DB HELPERS (MEMORY-AWARE FAST EXPORT)
# ============================================================
from sqlalchemy import create_engine, text
from io import StringIO
import csv
import math
import gc
import pandas as pd
from tqdm.auto import tqdm

DB_USER = "postgres"
DB_PASSWORD = "Nestle123"
DB_HOST = "127.0.0.1"
DB_PORT = "5432"
DB_NAME = "nestle_forecasting"
SCHEMA = "retail"

engine = create_engine(
    f"postgresql+psycopg2://{DB_USER}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/{DB_NAME}",
    future=True,
    pool_pre_ping=True,
)

def _normalize_for_sql(df):
    out = df.copy()
    for c in out.columns:
        if str(out[c].dtype).startswith("datetime64"):
            out[c] = pd.to_datetime(out[c], errors="coerce")
        elif pd.api.types.is_categorical_dtype(out[c]):
            out[c] = out[c].astype("string")
    out = out.replace({pd.NA: None})
    return out

def verify_table(table_name, schema=SCHEMA):
    q = text(f'SELECT COUNT(*) AS n FROM "{schema}"."{table_name}"')
    with engine.begin() as conn:
        return int(pd.read_sql(q, conn).iloc[0, 0])

def write_df_fast(df, table_name, schema=SCHEMA, if_exists="replace", chunk_size=100000):
    """Memory-aware PostgreSQL write using chunked COPY."""
    if df is None:
        print(f"[skip] {table_name}: df is None")
        return 0
    if len(df) == 0:
        print(f"[skip] {table_name}: 0 rows")
        with engine.begin() as conn:
            conn.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        return 0

    out = _normalize_for_sql(df)
    total_rows = len(out)

    with engine.begin() as conn:
        conn.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))

    raw = None
    cur = None
    try:
        raw = engine.raw_connection()
        cur = raw.cursor()
        if if_exists == "replace":
            cur.execute(f'DROP TABLE IF EXISTS "{schema}"."{table_name}" CASCADE')
            raw.commit()

        out.head(0).to_sql(
            table_name,
            engine,
            schema=schema,
            if_exists="replace" if if_exists == "replace" else "append",
            index=False,
        )

        cols = ",".join([f'"{c}"' for c in out.columns])

        for start in tqdm(range(0, total_rows, chunk_size), desc=f"Writing {table_name}", unit="chunk"):
            chunk = out.iloc[start:start + chunk_size]
            buffer = StringIO()
            chunk.to_csv(buffer, index=False, header=False, na_rep="", quoting=csv.QUOTE_MINIMAL)
            buffer.seek(0)
            cur.copy_expert(
                f'COPY "{schema}"."{table_name}" ({cols}) FROM STDIN WITH CSV',
                buffer
            )
            raw.commit()

        written = verify_table(table_name, schema=schema)
        print(f"[ok] {schema}.{table_name}: {written:,} rows written via chunked COPY")
        return written
    except Exception as e:
        if raw is not None:
            raw.rollback()
        print(f"[warn] COPY failed for {schema}.{table_name}: {e}")
        out.to_sql(
            table_name,
            engine,
            schema=schema,
            if_exists=if_exists,
            index=False,
            method="multi",
            chunksize=10000,
        )
        written = verify_table(table_name, schema=schema)
        print(f"[ok] {schema}.{table_name}: {written:,} rows written via chunked to_sql")
        return written
    finally:
        if cur is not None:
            cur.close()
        if raw is not None:
            raw.close()


# In[3]:


get_ipython().system('pip install chardet')


# In[4]:


# Cell 2: Imports
import re
from pathlib import Path

import chardet
import numpy as np
import pandas as pd


# In[5]:


# uploader = FileUpload(accept='.csv', multiple=True)
# display(uploader)


# In[6]:


# from IPython.display import display

# if uploader.value:
#     print(f"{len(uploader.value)} file(s) uploaded:")
#     for fileinfo in uploader.value:
#         print("-", fileinfo['name'], f"({fileinfo['size']} bytes)")
# else:
#     print("⚠️ No files uploaded yet. Make sure to click the 'Upload' button after selecting files.")


# In[7]:


# Cell 3: Directory setup
DATA_DIR = Path(r"D:\JN\Thesis\Dataset\raw")
CLEANED_DIR = Path(r"D:\JN\Thesis\Dataset\cleaned")


# In[ ]:





# In[8]:


import re

def find_col(df, pattern):
    for c in df.columns:
        if re.search(pattern, str(c), flags=re.IGNORECASE):
            return c
    return None


# In[9]:


def load_monthly_file_clean(path, source_name, *, min_months=1, enforce_full_year=False):
    import pandas as pd
    import chardet

    # -----------------------------
    # Detect encoding
    # -----------------------------
    with open(path, "rb") as f:
        raw = f.read(100000)

    enc_guess = chardet.detect(raw)["encoding"] or "utf-8"
    print(f"[{source_name}] Detected encoding: {enc_guess}")

    try:
        df = pd.read_csv(
            path,
            low_memory=False,
            dtype={"Product Code": "string"},
            encoding=enc_guess
        )
    except UnicodeDecodeError:
        print(f"[{source_name}] Fallback to latin1 due to decode error")
        df = pd.read_csv(
            path,
            low_memory=False,
            dtype={"Product Code": "string"},
            encoding="latin1"
        )

    # -----------------------------
    # Normalize headers
    # -----------------------------
    df.columns = [str(c).strip().strip("'\"") for c in df.columns]

    # -----------------------------
    # Detect raw columns
    # -----------------------------
    col_units_ty = find_col(df, r"units\s*sold.*\bTY\b")
    col_units_ly = find_col(df, r"units\s*sold.*\bLY\b")
    col_sales_ty = find_col(df, r"net\s*sales.*\bTY\b")
    col_sales_ly = find_col(df, r"net\s*sales.*\bLY\b")

    col_product_code = find_col(df, r"product\s*code")
    col_product_desc = find_col(df, r"product\s*description")
    col_period = find_col(df, r"\bperiod\b")
    col_region = find_col(df, r"nestle\s*region")
    col_cluster = find_col(df, r"nestle\s*store\s*cluster")
    col_store_code = find_col(df, r"store\s*code")
    col_store_desc = find_col(df, r"store\s*(description|name)")

    # -----------------------------
    # Canonicalize columns
    # -----------------------------
    if col_units_ty is not None:
        df["Units Sold TY"] = df[col_units_ty]

    if col_units_ly is not None:
        df["Units Sold LY"] = df[col_units_ly]

    if col_sales_ty is not None:
        df["Net Sales TY (Ex-VAT)"] = df[col_sales_ty]

    if col_sales_ly is not None:
        df["Net Sales LY (Ex-VAT)"] = df[col_sales_ly]

    if col_product_code is not None:
        df["Product Code"] = df[col_product_code]

    if col_product_desc is not None:
        df["Product Description"] = df[col_product_desc]

    if col_period is not None:
        df["Period"] = df[col_period]

    if col_region is not None:
        df["NESTLE REGION"] = df[col_region]

    if col_cluster is not None:
        df["NESTLE STORE CLUSTER"] = df[col_cluster]

    if col_store_code is not None:
        df["Store Code"] = df[col_store_code]

    if col_store_desc is not None:
        df["Store Description"] = df[col_store_desc]

    # -----------------------------
    # Brand from source/file name
    # -----------------------------
    src = str(source_name).lower()
    pth = str(path).lower()

    if "wyeth" in src or "wyeth" in pth:
        df["Brand"] = "WYETH"
    elif "nestle" in src or "nestle" in pth:
        df["Brand"] = "NESTLE"
    else:
        df["Brand"] = "NESTLE"
        print(f"[{source_name}] ⚠ Brand not found in file/source. Defaulted to NESTLE.")

    # -----------------------------
    # Required columns check
    # -----------------------------
    required_before_ds = [
        "Product Code",
        "Product Description",
        "Period",
        "NESTLE REGION",
        "NESTLE STORE CLUSTER",
        "Store Code",
        "Store Description",
        "Units Sold TY",
        "Units Sold LY",
        "Net Sales TY (Ex-VAT)",
        "Net Sales LY (Ex-VAT)",
        "Brand"
    ]

    missing_before_ds = [col for col in required_before_ds if col not in df.columns]
    if missing_before_ds:
        raise KeyError(f"[{source_name}] Missing required columns: {missing_before_ds}")

    # -----------------------------
    # Parse Period -> ds
    # -----------------------------
    ds_raw = pd.to_datetime(df["Period"], errors="coerce")

    if ds_raw.isna().mean() > 0.30:
        ds_raw = pd.to_datetime(df["Period"], errors="coerce", format="%m/%d/%y")

    df["ds"] = ds_raw.dt.to_period("M").dt.to_timestamp(how="start")

    before = len(df)
    df = df[df["ds"].notna()].copy()
    after = len(df)

    if after < before:
        print(f"[{source_name}] Dropped {before - after} rows with unparseable Period -> ds")

    # -----------------------------
    # Numeric conversion
    # -----------------------------
    df["Units Sold TY"] = pd.to_numeric(df["Units Sold TY"], errors="coerce")
    df["Units Sold LY"] = pd.to_numeric(df["Units Sold LY"], errors="coerce")
    df["Net Sales TY (Ex-VAT)"] = pd.to_numeric(df["Net Sales TY (Ex-VAT)"], errors="coerce")
    df["Net Sales LY (Ex-VAT)"] = pd.to_numeric(df["Net Sales LY (Ex-VAT)"], errors="coerce")

    # -----------------------------
    # Clip negatives
    # -----------------------------
    neg_units_ty = (df["Units Sold TY"] < 0).sum()
    neg_units_ly = (df["Units Sold LY"] < 0).sum()
    neg_sales_ty = (df["Net Sales TY (Ex-VAT)"] < 0).sum()
    neg_sales_ly = (df["Net Sales LY (Ex-VAT)"] < 0).sum()

    if neg_units_ty > 0:
        print(f"[{source_name}] ⚠ {neg_units_ty} negative values in Units Sold TY - clipping to 0.")
    if neg_units_ly > 0:
        print(f"[{source_name}] ⚠ {neg_units_ly} negative values in Units Sold LY - clipping to 0.")
    if neg_sales_ty > 0:
        print(f"[{source_name}] ⚠ {neg_sales_ty} negative values in Net Sales TY (Ex-VAT) - clipping to 0.")
    if neg_sales_ly > 0:
        print(f"[{source_name}] ⚠ {neg_sales_ly} negative values in Net Sales LY (Ex-VAT) - clipping to 0.")

    df["Units Sold TY"] = df["Units Sold TY"].clip(lower=0)
    df["Units Sold LY"] = df["Units Sold LY"].clip(lower=0)
    df["Net Sales TY (Ex-VAT)"] = df["Net Sales TY (Ex-VAT)"].clip(lower=0)
    df["Net Sales LY (Ex-VAT)"] = df["Net Sales LY (Ex-VAT)"].clip(lower=0)

    # -----------------------------
    # Source tracking
    # -----------------------------
    df["source_file"] = source_name

    # -----------------------------
    # Final columns
    # -----------------------------
    final_cols = [
        "Brand",
        "Product Code",
        "Product Description",
        "Period",
        "ds",
        "NESTLE REGION",
        "NESTLE STORE CLUSTER",
        "Store Code",
        "Store Description",
        "Units Sold TY",
        "Units Sold LY",
        "Net Sales TY (Ex-VAT)",
        "Net Sales LY (Ex-VAT)",
        "source_file"
    ]

    missing_final = [col for col in final_cols if col not in df.columns]
    if missing_final:
        raise KeyError(f"[{source_name}] Missing final columns after standardization: {missing_final}")

    df = df[final_cols].copy()

    # -----------------------------
    # Missing-value check
    # -----------------------------
    for col in final_cols:
        missing_ratio = df[col].isna().mean()
        if missing_ratio > 0.10:
            raise ValueError(
                f"[{source_name}] Column '{col}' has {missing_ratio:.1%} missing values — exceeds 10% threshold."
            )
        df = df[df[col].notna()].copy()

    # -----------------------------
    # Month coverage check
    # -----------------------------
    months = df["ds"].dropna().dt.to_period("M")
    n_months = months.nunique()

    if n_months < min_months:
        raise ValueError(f"[{source_name}] Only {n_months} distinct months found (<{min_months}).")

    years = df["ds"].dt.year.dropna()
    latest_year = int(years.max())
    years_present = sorted(years.unique().tolist())

    issues = []
    for y in years_present:
        months_y = set(df.loc[df["ds"].dt.year == y, "ds"].dt.month.unique())
        missing = set(range(1, 13)) - months_y
        if missing:
            if enforce_full_year or (y != latest_year):
                issues.append((y, sorted(missing)))

    if issues:
        msg = "; ".join([f"{y}: missing {m}" for y, m in issues])
        raise ValueError(f"[{source_name}] Incomplete historical year(s): {msg}")
    else:
        latest_months = sorted(df.loc[df["ds"].dt.year == latest_year, "ds"].dt.month.unique())
        print(f"[{source_name}] ✅ Parsed ds. Latest year {latest_year} months present: {latest_months}")

    print(f"[{source_name}] ✅ Clean shape: {df.shape}")
    return df


# In[10]:


#Cell 6: Files to process - NOW INCLUDING WYETH FILES
files_to_clean = [
     (DATA_DIR / "Nestle_2023.csv", "2023"),
     (DATA_DIR / "Nestle_Raw.csv", "2024_2025"),
     (DATA_DIR / "Wyeth_2023.csv", "2023_wyeth"), 
     (DATA_DIR / "Wyeth_Raw.csv", "2024_2025_wyeth")  
 ]


# In[11]:


# Cell 6.5: Inspect raw files before cleaning
for path, source_name in files_to_clean:
    print(f"\n🔍 Inspecting: {path.name} ({source_name})")

    # Detect encoding
    with open(path, 'rb') as f:
        raw = f.read(100000)
    enc_guess = chardet.detect(raw)['encoding'] or 'utf-8'

    try:
        df = pd.read_csv(path, encoding=enc_guess, low_memory=False)
    except UnicodeDecodeError:
        print("⚠️ UnicodeDecodeError — retrying with 'latin1' encoding")
        df = pd.read_csv(path, encoding='latin1', low_memory=False)

    print(f"✅ Loaded with shape: {df.shape}")
    print("\n📌 Columns:")
    print(df.columns.tolist())

    print("\n🕳️ Missing values per column:")
    print(df.isna().sum())



# In[12]:


cleaned_dfs = []

for path, source_name in files_to_clean:
    print("Processing", path.name)

    df_clean = load_monthly_file_clean(path, source_name)

    print("Columns:", df_clean.columns.tolist())
    print("Shape:", df_clean.shape)

    cleaned_dfs.append(df_clean)


# In[13]:


# Cell 7.5: Inspect cleaned DataFrames
for i, df in enumerate(cleaned_dfs):
    print(f"\n🧼 Cleaned DataFrame {i+1} — Source: {files_to_clean[i][1]}")
    print(f"✅ Shape: {df.shape}")

    print("\n📌 Columns:")
    print(df.columns.tolist())

    print("\n🕳️ Missing values per column:")
    print(df.isna().sum())



# In[14]:


for i, df in enumerate(cleaned_dfs):
    source_name = files_to_clean[i][1]

    by_year = (
        df.dropna(subset=["ds"])
          .groupby(df["ds"].dt.year)["ds"]
          .apply(lambda s: sorted(s.dt.month.unique()))
    )

    print(f"\n[{source_name}] months by year:")
    for y, months in by_year.items():
        missing = sorted(set(range(1,13)) - set(months))
        status = "✅ full" if not missing else f"⚠️ missing {missing}"
        print(f"  {y}: {months}  {status}")


# In[15]:


# Cell 8: Consolidate all cleaned files and save only nestle_clean_panel.parquet

panel_raw = pd.concat(cleaned_dfs, ignore_index=True)

print("✅ Consolidated rows:", len(panel_raw))
print("✅ Date span:", panel_raw["ds"].min(), "→", panel_raw["ds"].max())
print("✅ Unique products:", panel_raw["Product Code"].nunique())
print("✅ Unique stores:", panel_raw["Store Code"].nunique())
print("✅ Brands:", sorted(panel_raw["Brand"].dropna().unique().tolist()))

# Ensure monthly frequency is normalized to month start
panel_raw["ds"] = pd.to_datetime(panel_raw["ds"], errors="coerce").dt.to_period("M").dt.to_timestamp()

bad = int(panel_raw["ds"].isna().sum())
if bad:
    raise ValueError(f"'ds' parsing failed for {bad} rows. Check Period parsing upstream.")

# --------------------------------------------------
# Alias cleaning output columns to downstream names
# --------------------------------------------------
if "Net Sales TY" not in panel_raw.columns and "Net Sales TY (Ex-VAT)" in panel_raw.columns:
    panel_raw["Net Sales TY"] = panel_raw["Net Sales TY (Ex-VAT)"]

if "Net Sales LY" not in panel_raw.columns and "Net Sales LY (Ex-VAT)" in panel_raw.columns:
    panel_raw["Net Sales LY"] = panel_raw["Net Sales LY (Ex-VAT)"]

if "Store Name" not in panel_raw.columns and "Store Description" in panel_raw.columns:
    panel_raw["Store Name"] = panel_raw["Store Description"]

# Required / optional schema
REQUIRED = [
    "Brand",
    "Product Code",
    "Product Description",
    "Store Code",
    "Store Description",
    "Period",
    "ds",
    "Units Sold TY",
    "Net Sales TY",
    "NESTLE STORE CLUSTER",
    "NESTLE REGION",
    "source_file",
]

OPTIONAL = ["Units Sold LY", "Net Sales LY"]

missing = sorted(set(REQUIRED) - set(panel_raw.columns))
assert not missing, f"Missing required columns after consolidation: {missing}"

# Required fields must be non-null
id_nulls = {c: int(panel_raw[c].isna().sum()) for c in REQUIRED}
print("Null counts (required):", id_nulls)
assert all(v == 0 for v in id_nulls.values()), "Found NULLs in required fields. Fix upstream cleaning."

# Measure dtypes
for m in ["Units Sold TY", "Net Sales TY"] + OPTIONAL:
    if m in panel_raw.columns:
        assert pd.api.types.is_numeric_dtype(panel_raw[m]), f"{m} is not numeric"

# Duplicate handling at thesis grain
GRAIN = ["Brand", "Product Code", "Store Code", "ds"]
dup_rows = int(panel_raw.duplicated(subset=GRAIN, keep=False).sum())
print("Duplicate rows at grain:", dup_rows)

agg_measures = {
    "Units Sold TY": "sum",
    "Net Sales TY": "sum",
}

if "Units Sold LY" in panel_raw.columns:
    agg_measures["Units Sold LY"] = "sum"

if "Net Sales LY" in panel_raw.columns:
    agg_measures["Net Sales LY"] = "sum"

first_cols = [c for c in panel_raw.columns if c not in GRAIN and c not in agg_measures]

if dup_rows:
    panel = (
        panel_raw
        .groupby(GRAIN, as_index=False)
        .agg({**{c: "first" for c in first_cols}, **agg_measures})
    )
else:
    panel = panel_raw.copy()

print("✅ Panel shape after grain enforcement:", panel.shape)

# Hierarchy canonicalization for MinT
for c in ["NESTLE STORE CLUSTER", "NESTLE REGION"]:
    panel[c] = (
        panel[c]
        .astype("string")
        .str.strip()
        .replace({"": pd.NA, "nan": pd.NA, "None": pd.NA})
    )

store_cluster_nuniq = panel.groupby(["Brand", "Store Code"])["NESTLE STORE CLUSTER"].nunique(dropna=True)
store_region_nuniq = panel.groupby(["Brand", "Store Code"])["NESTLE REGION"].nunique(dropna=True)
cluster_region_nuniq = panel.groupby(["Brand", "NESTLE STORE CLUSTER"])["NESTLE REGION"].nunique(dropna=True)

print("Bad Store→Cluster mappings:", int((store_cluster_nuniq > 1).sum()))
print("Bad Store→Region mappings:", int((store_region_nuniq > 1).sum()))
print("Bad Cluster→Region mappings:", int((cluster_region_nuniq > 1).sum()))

def canonical_mode_latest(df, key_cols, value_col):
    tmp = df[key_cols + ["ds", value_col]].dropna(subset=[value_col]).copy()
    counts = (
        tmp.groupby(key_cols + [value_col], as_index=False)
           .agg(n=("ds", "count"), last_ds=("ds", "max"))
    )
    counts = counts.sort_values(
        key_cols + ["n", "last_ds"],
        ascending=[True] * len(key_cols) + [False, False]
    )
    return counts.drop_duplicates(subset=key_cols, keep="first")[key_cols + [value_col]]

canon_store_cluster = canonical_mode_latest(panel, ["Brand", "Store Code"], "NESTLE STORE CLUSTER")
canon_store_region = canonical_mode_latest(panel, ["Brand", "Store Code"], "NESTLE REGION")
canon_cluster_region = canonical_mode_latest(panel, ["Brand", "NESTLE STORE CLUSTER"], "NESTLE REGION")

panel = panel.drop(columns=["NESTLE STORE CLUSTER", "NESTLE REGION"]).merge(
    canon_store_cluster, on=["Brand", "Store Code"], how="left"
).merge(
    canon_store_region, on=["Brand", "Store Code"], how="left"
)

panel = panel.drop(columns=["NESTLE REGION"]).merge(
    canon_cluster_region, on=["Brand", "NESTLE STORE CLUSTER"], how="left"
)

# Final sales sanity check before export
print("\n=== FINAL CLEAN PANEL SALES CHECK ===")
for col in ["Net Sales TY", "Net Sales LY", "Units Sold TY", "Units Sold LY"]:
    if col in panel.columns:
        s = pd.to_numeric(
            panel[col].astype(str).str.replace(r"[₱,]", "", regex=True).str.strip(),
            errors="coerce"
        )
        print(
            f"{col}: dtype={panel[col].dtype} "
            f"non-null={int(s.notna().sum())} "
            f"non-zero={int((s.fillna(0) != 0).sum())} "
            f"max={float(s.max()) if s.notna().any() else None}"
        )

# --------------------------------------------------
# Force parquet-safe dtypes
# --------------------------------------------------

# string/id columns
string_cols = [
    "Brand",
    "Product Code",
    "Product Description",
    "Store Code",
    "Store Description",
    "Store Name",
    "Period",
    "NESTLE STORE CLUSTER",
    "NESTLE REGION",
    "source_file",
]

for col in string_cols:
    if col in panel.columns:
        panel[col] = (
            panel[col]
            .astype("string")
            .str.strip()
        )

# numeric measure columns
numeric_cols = [
    "Units Sold TY",
    "Units Sold LY",
    "Net Sales TY",
    "Net Sales LY",
    "Net Sales TY (Ex-VAT)",
    "Net Sales LY (Ex-VAT)",
]

for col in numeric_cols:
    if col in panel.columns:
        panel[col] = pd.to_numeric(panel[col], errors="coerce")

# datetime column
if "ds" in panel.columns:
    panel["ds"] = pd.to_datetime(panel["ds"], errors="coerce")

# optional: show final dtypes
print("\n=== FINAL DTYPES BEFORE EXPORT ===")
print(panel.dtypes)

# Save only the final file needed by Feature Engineering
CLEANED_DIR.mkdir(parents=True, exist_ok=True)
output_path = CLEANED_DIR / "nestle_clean_panel.parquet"
panel.to_parquet(output_path, index=False)

print("📦 Saved final clean panel:", output_path)
print("✅ Only one output file was saved: nestle_clean_panel.parquet")


# In[16]:


import pandas as pd
import numpy as np

# ============================================================
# 1. COPY DATAFRAME
# Replace df with your actual dataframe name if different
# ============================================================
panel = df.copy()

# ============================================================
# 2. CHECK AVAILABLE COLUMNS
# ============================================================
print("Columns in dataframe:")
print(panel.columns.tolist())

# ============================================================
# 3. DEFINE SOURCE COLUMNS
# Change these if your actual column names are slightly different
# ============================================================
region_col = "NESTLE REGION"
cluster_col = "NESTLE STORE CLUSTER"

if region_col not in panel.columns:
    raise KeyError(f"Missing region column: {region_col}")

if cluster_col not in panel.columns:
    raise KeyError(f"Missing cluster column: {cluster_col}")

# ============================================================
# 4. CLEAN RAW TEXT
# ============================================================
panel[region_col] = panel[region_col].astype(str).str.strip().str.upper()
panel[cluster_col] = panel[cluster_col].astype(str).str.strip().str.upper()

# ============================================================
# 5. REGION LABEL MAPPING
# This converts abbreviations / messy labels into your expected names
# ============================================================
region_map = {
    "GMA": "GMA",
    "NCL": "North Luzon",
    "NORTH LUZON": "North Luzon",
    "NL": "North Luzon",
    "SL": "South Luzon",
    "SOUTH LUZON": "South Luzon",
    "VIS": "Visayas",
    "VISAYAS": "Visayas",
    "MIN": "Mindanao",
    "MINDANAO": "Mindanao"
}

# ============================================================
# 6. CLUSTER LABEL MAPPING
# Standardizes cluster names
# ============================================================
cluster_map = {
    "COMMERCIAL": "Commercial",
    "COMMUNITY": "Community",
    "HEALTH CARE FACILITY": "Health Care Facility",
    "HCF": "Health Care Facility",
    "MALL": "Mall"
}

# ============================================================
# 7. CREATE CLEAN LABELED COLUMNS
# If value is not in mapping, keep original value as fallback
# ============================================================
panel["region_label"] = panel[region_col].map(region_map).fillna(panel[region_col].str.title())
panel["cluster_label"] = panel[cluster_col].map(cluster_map).fillna(panel[cluster_col].str.title())

# ============================================================
# 8. OPTIONAL: OVERWRITE ORIGINAL COLUMNS
# Uncomment if you want the original columns replaced
# ============================================================
# panel[region_col] = panel["region_label"]
# panel[cluster_col] = panel["cluster_label"]

# ============================================================
# 9. SHOW ALL UNIQUE LABELED VALUES
# ============================================================
all_regions = sorted(panel["region_label"].dropna().unique().tolist())
all_clusters = sorted(panel["cluster_label"].dropna().unique().tolist())

print("\nAll labeled Regions:")
for r in all_regions:
    print("-", r)

print("\nAll labeled Clusters:")
for c in all_clusters:
    print("-", c)

# ============================================================
# 10. OPTIONAL: CHECK UNMAPPED ORIGINAL VALUES
# Useful for debugging unexpected labels
# ============================================================
raw_regions = sorted(panel[region_col].dropna().unique().tolist())
raw_clusters = sorted(panel[cluster_col].dropna().unique().tolist())

unmapped_regions = [x for x in raw_regions if x not in region_map]
unmapped_clusters = [x for x in raw_clusters if x not in cluster_map]

print("\nUnmapped raw region values:")
print(unmapped_regions if unmapped_regions else "None")

print("\nUnmapped raw cluster values:")
print(unmapped_clusters if unmapped_clusters else "None")

# ============================================================
# 11. PREVIEW
# ============================================================
display(
    panel[[region_col, "region_label", cluster_col, "cluster_label"]]
    .drop_duplicates()
    .sort_values(["region_label", "cluster_label"])
    .reset_index(drop=True)
)


# In[ ]:





# In[17]:


# ============================================================
# DB EXPORT: CLEANED PANEL (DB-ONLY MAIN OUTPUT)
# ============================================================
cleaned_export = panel_raw.copy()
written = write_df_fast(cleaned_export, "stg_cleaned_sales_panel")
print("stg_cleaned_sales_panel rows:", written)
print("DB-only handoff ready. Parquet is no longer required by downstream notebooks.")

