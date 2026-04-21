#!/usr/bin/env python
# coding: utf-8

# # C2G Notebook
# 
# This notebook builds **Coherent Contribution-to-Growth (C2G)** outputs from the reconciled MinT forecasts.
# 
# ## Flow
# 1. Load historical panel and MinT reconciled forecasts
# 2. Build bottom-level actual baselines
# 3. Compute bottom-level C2G metrics
# 4. Aggregate coherently to Store, Cluster, Region, and National levels
# 5. Export parquet outputs and database tables
# 6. Optionally visualize the latest C2G drivers in-notebook
# 

# ## 1) Imports, helpers, and database utilities

# In[1]:


import gc
from io import StringIO
import csv

import numpy as np
import pandas as pd
from sqlalchemy import create_engine, text
from tqdm.auto import tqdm

pd.set_option("display.max_columns", 200)
pd.set_option("display.width", 200)

# ============================================================
# DB CONFIG (MATCHES YOUR OTHER NOTEBOOKS)
# ============================================================
DB_USER = "postgres"
DB_PASSWORD = "Nestle123"
DB_HOST = "127.0.0.1"
DB_PORT = "5432"
DB_NAME = "nestle_forecasting"
SCHEMA = "retail"

READ_CHUNK_SIZE = 25_000
WRITE_CHUNK_SIZE = 25_000

engine = create_engine(
    f"postgresql+psycopg2://{DB_USER}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/{DB_NAME}",
    future=True,
    pool_pre_ping=True,
)

# ============================================================
# INPUT CONFIG
# ============================================================
MINT_TABLE = f"{SCHEMA}.stg_mint_reconciled_forecast"
PANEL_TABLE = f"{SCHEMA}.stg_feature_engineered_panel"

print("MINT_TABLE :", MINT_TABLE)
print("PANEL_TABLE:", PANEL_TABLE)

def optimize_dataframe_memory(df, category_threshold=0.35):
    if df is None or df.empty:
        return df
    out = df.copy()
    for col in out.columns:
        series = out[col]
        if pd.api.types.is_integer_dtype(series):
            out[col] = pd.to_numeric(series, downcast="integer")
        elif pd.api.types.is_float_dtype(series):
            out[col] = pd.to_numeric(series, downcast="float")
        elif pd.api.types.is_object_dtype(series):
            non_null = series.dropna()
            if len(non_null) == 0:
                continue
            unique_ratio = non_null.nunique(dropna=True) / max(len(non_null), 1)
            if unique_ratio <= category_threshold:
                out[col] = series.astype("category")
    return out

def read_sql_fast(query: str, parse_dates=None, chunksize=READ_CHUNK_SIZE, label="query") -> pd.DataFrame:
    frames = []
    for chunk in tqdm(
        pd.read_sql_query(text(query), engine, parse_dates=parse_dates, chunksize=chunksize),
        desc=f"Loading {label}",
        unit="chunk",
    ):
        frames.append(optimize_dataframe_memory(chunk))
    if not frames:
        return pd.DataFrame()
    out = pd.concat(frames, ignore_index=True)
    del frames
    gc.collect()
    return out

def print_df_memory(df, name):
    if df is None:
        print(f"{name}: None")
        return
    mem_mb = df.memory_usage(deep=True).sum() / (1024 ** 2)
    print(f"{name}: shape={df.shape}, memory={mem_mb:,.2f} MB")

def write_df_fast(df: pd.DataFrame, table_name: str, schema: str = SCHEMA, if_exists: str = "replace"):
    df = optimize_dataframe_memory(df)
    with engine.begin() as conn:
        conn.execute(text(f'DROP TABLE IF EXISTS "{schema}"."{table_name}"'))
    df.to_sql(
        table_name,
        engine,
        schema=schema,
        if_exists=if_exists,
        index=False,
        method="multi",
        chunksize=WRITE_CHUNK_SIZE,
    )

def verify_table(table_name: str, schema: str = SCHEMA):
    with engine.begin() as conn:
        return conn.execute(text(f'SELECT COUNT(*) FROM "{schema}"."{table_name}"')).scalar()


# In[2]:


def standardize_columns(df):
    if df is None:
        return df
    out = df.copy()
    out.columns = (
        out.columns.astype(str)
        .str.strip()
        .str.lower()
        .str.replace(" ", "_")
        .str.replace(r"[^\w]", "", regex=True)
    )
    rename_map = {
        "date": "ds",
        "month_start_date": "ds",
        "productcode": "product_code",
        "storecode": "store_code",
        "forecast": "base_forecast",
        "prediction": "base_forecast",
        "pred": "base_forecast",
        "forecast_sales": "base_forecast",
        "mint_forecast": "reconciled_forecast",
        "reconciled_pred": "reconciled_forecast",
        "trend_sales": "trend",
        "seasonal": "seasonal_multiplier",
    }
    cols = {k: v for k, v in rename_map.items() if k in out.columns and v not in out.columns}
    return out.rename(columns=cols)

def verify_required_columns(df, required_cols, df_name):
    missing = [c for c in required_cols if c not in df.columns]
    if missing:
        raise KeyError(f"Missing required columns in {df_name}: {missing}")

def safe_share(numer, denom):
    numer = pd.Series(numer, copy=False)
    denom = pd.Series(denom, copy=False)
    return np.where(np.abs(denom) > 1e-12, numer / denom, np.nan)

def safe_pct(numerator, denominator, scale=100.0):
    numerator = pd.Series(numerator, copy=False)
    denominator = pd.Series(denominator, copy=False).replace(0, np.nan)
    return (numerator / denominator) * scale


# ## 2) Paths and input loading

# In[3]:


mint_bottom = read_sql_fast(
    f"""
        SELECT
            ds,
            product_code,
            store_code,
            nestle_store_cluster,
            nestle_region,
            reconciled_forecast
        FROM {MINT_TABLE}
    """,
    parse_dates=["ds"],
    label="mint_bottom",
)

actuals = read_sql_fast(
    f"""
        SELECT
            ds,
            "Product Code",
            "Store Code",
            "NESTLE STORE CLUSTER",
            "NESTLE REGION",
            SUM(net_sales_ty) AS actual_sales
        FROM {PANEL_TABLE}
        GROUP BY
            ds,
            "Product Code",
            "Store Code",
            "NESTLE STORE CLUSTER",
            "NESTLE REGION"
    """,
    parse_dates=["ds"],
    label="actuals",
)

lookup = read_sql_fast(
    f"""
        SELECT DISTINCT
            "Product Code",
            "Store Code",
            "NESTLE STORE CLUSTER",
            "NESTLE REGION",
            "Product Description",
            "Store Description",
            "Category",
            "Brand",
            eligible_for_model
        FROM {PANEL_TABLE}
    """,
    label="lookup",
)

mint_bottom = standardize_columns(mint_bottom)
actuals = standardize_columns(actuals)
lookup = standardize_columns(lookup)

verify_required_columns(
    mint_bottom,
    ["ds", "product_code", "store_code", "nestle_store_cluster", "nestle_region", "reconciled_forecast"],
    "mint_bottom",
)

verify_required_columns(
    actuals,
    ["ds", "product_code", "store_code", "nestle_store_cluster", "nestle_region", "actual_sales"],
    "actuals",
)

verify_required_columns(
    lookup,
    ["product_code", "store_code", "nestle_store_cluster", "nestle_region"],
    "lookup",
)

mint_bottom["reconciled_forecast"] = pd.to_numeric(mint_bottom["reconciled_forecast"], errors="coerce")
actuals["actual_sales"] = pd.to_numeric(actuals["actual_sales"], errors="coerce")

print_df_memory(mint_bottom, "mint_bottom")
print_df_memory(actuals, "actuals")
print_df_memory(lookup, "lookup")

display(mint_bottom.head())
display(actuals.head())
display(lookup.head())


# In[4]:


print("DB-only mode: skipping local parquet output paths. PostgreSQL tables are the official outputs.")


# ## 3) Standardize key text columns and build bottom-level inputs
# 
# This section:
# - standardizes key text fields
# - builds historical actuals at the same grain as the MinT bottom-level forecast
# - attaches stable lookup metadata for descriptions and optional fields
# 

# In[5]:


TEXT_COLS = [
    "product_code",
    "product_description",
    "store_code",
    "store_description",
    "nestle_store_cluster",
    "nestle_region",
    "category",
    "brand",
]

for c in TEXT_COLS:
    if c in lookup.columns:
        lookup[c] = lookup[c].astype("string").str.strip()
    if c in mint_bottom.columns:
        mint_bottom[c] = mint_bottom[c].astype("string").str.strip()

meta_candidates = ["category", "brand", "eligible_for_model"]
meta_cols = [c for c in meta_candidates if c in lookup.columns]

series_keys = [
    c for c in ["nestle_region", "nestle_store_cluster", "store_code", "product_code"]
    if c in lookup.columns
]

lookup = (
    lookup
    .drop_duplicates()
    .sort_values(series_keys)
    .groupby(series_keys, as_index=False, observed=True)
    .last()
)

mint_group_cols = [
    "ds",
    "nestle_region",
    "nestle_store_cluster",
    "store_code",
    "product_code",
]

fcst = (
    mint_bottom.groupby(mint_group_cols, as_index=False, observed=True)["reconciled_forecast"]
    .sum()
)

fcst = fcst.merge(
    lookup,
    on=series_keys,
    how="left",
    validate="many_to_one"
)

print("Actuals shape:", actuals.shape)
print("Forecast frame shape:", fcst.shape)
print_df_memory(fcst, "fcst after lookup merge")
display(fcst.head())


# ## 4) Add comparison baselines and compute bottom-level C2G metrics
# 
# Two baselines are attached:
# - **Same month last year** for YoY attribution
# - **Latest observed actual** for operational directional comparison
# 

# In[6]:


ly_actuals = actuals.rename(columns={"actual_sales": "actual_ly"}).copy()
ly_actuals["ds"] = ly_actuals["ds"] + pd.DateOffset(years=1)

bottom_keys = [
    "ds",
    "nestle_region",
    "nestle_store_cluster",
    "store_code",
    "product_code",
]

fcst = fcst.merge(
    ly_actuals,
    on=bottom_keys,
    how="left",
    validate="many_to_one"
)

latest_actual = (
    actuals.sort_values(series_keys + ["ds"])
    .groupby(series_keys, as_index=False, observed=True)
    .tail(1)
    .rename(columns={"ds": "last_actual_ds", "actual_sales": "actual_last"})
)

fcst = fcst.merge(
    latest_actual,
    on=series_keys,
    how="left",
    validate="many_to_one"
)

for c in ["reconciled_forecast", "actual_ly", "actual_last"]:
    if c in fcst.columns:
        fcst[c] = pd.to_numeric(fcst[c], errors="coerce").fillna(0.0).astype("float32")

fcst["growth_vs_ly_abs"] = (fcst["reconciled_forecast"] - fcst["actual_ly"]).astype("float32")
fcst["growth_vs_last_abs"] = (fcst["reconciled_forecast"] - fcst["actual_last"]).astype("float32")

month_totals = (
    fcst.groupby("ds", as_index=False, observed=True)[["growth_vs_ly_abs", "growth_vs_last_abs"]]
    .sum()
    .rename(columns={
        "growth_vs_ly_abs": "national_growth_vs_ly_abs",
        "growth_vs_last_abs": "national_growth_vs_last_abs",
    })
)

fcst = fcst.merge(month_totals, on="ds", how="left", validate="many_to_one")
fcst["c2g_vs_ly"] = safe_share(fcst["growth_vs_ly_abs"], fcst["national_growth_vs_ly_abs"]).astype("float32")
fcst["c2g_vs_last"] = safe_share(fcst["growth_vs_last_abs"], fcst["national_growth_vs_last_abs"]).astype("float32")
fcst["growth_vs_ly_pct"] = safe_share(fcst["growth_vs_ly_abs"], fcst["actual_ly"]).astype("float32")
fcst["growth_vs_last_pct"] = safe_share(fcst["growth_vs_last_abs"], fcst["actual_last"]).astype("float32")

bottom_output = optimize_dataframe_memory(fcst.copy())

del fcst, ly_actuals, latest_actual, month_totals, actuals, lookup, mint_bottom
gc.collect()

print_df_memory(bottom_output, "Bottom-level output")
display(bottom_output.head())


# ## 5) Aggregate C2G coherently across hierarchy levels
# 
# Because growth is additive, C2G stays coherent when it is built from the reconciled bottom-level forecast and summed upward.
# 

# In[7]:


def aggregate_level(df, group_cols, level_name):
    agg = (
        df.groupby(["ds"] + group_cols, as_index=False, observed=True)[
            ["reconciled_forecast", "actual_ly", "actual_last"]
        ].sum()
    )

    if "store_code" in group_cols and "store_description" in df.columns:
        store_lookup = (
            df[group_cols + ["store_description"]]
            .drop_duplicates()
            .groupby(group_cols, as_index=False, observed=True)
            .last()
        )
        agg = agg.merge(store_lookup, on=group_cols, how="left", validate="many_to_one")

    if "product_code" in group_cols and "product_description" in df.columns:
        prod_lookup = (
            df[group_cols + ["product_description"]]
            .drop_duplicates()
            .groupby(group_cols, as_index=False, observed=True)
            .last()
        )
        agg = agg.merge(prod_lookup, on=group_cols, how="left", validate="many_to_one")

    for c in [
        "nestle_region",
        "nestle_store_cluster",
        "store_code",
        "store_description",
        "product_code",
        "product_description",
    ] + meta_cols:
        if c not in agg.columns:
            agg[c] = pd.NA

    agg["level"] = level_name
    agg["growth_vs_ly_abs"] = (agg["reconciled_forecast"] - agg["actual_ly"]).astype("float32")
    agg["growth_vs_last_abs"] = (agg["reconciled_forecast"] - agg["actual_last"]).astype("float32")

    month_tot = (
        agg.groupby("ds", as_index=False, observed=True)[["growth_vs_ly_abs", "growth_vs_last_abs"]]
        .sum()
        .rename(columns={
            "growth_vs_ly_abs": "national_growth_vs_ly_abs",
            "growth_vs_last_abs": "national_growth_vs_last_abs",
        })
    )
    agg = agg.merge(month_tot, on="ds", how="left", validate="many_to_one")

    agg["c2g_vs_ly"] = safe_share(agg["growth_vs_ly_abs"], agg["national_growth_vs_ly_abs"]).astype("float32")
    agg["c2g_vs_last"] = safe_share(agg["growth_vs_last_abs"], agg["national_growth_vs_last_abs"]).astype("float32")
    agg["growth_vs_ly_pct"] = safe_share(agg["growth_vs_ly_abs"], agg["actual_ly"]).astype("float32")
    agg["growth_vs_last_pct"] = safe_share(agg["growth_vs_last_abs"], agg["actual_last"]).astype("float32")

    ordered_cols = [
        "ds",
        "level",
        "nestle_region",
        "nestle_store_cluster",
        "store_code",
        "store_description",
        "product_code",
        "product_description",
    ] + meta_cols + [
        "reconciled_forecast",
        "actual_ly",
        "actual_last",
        "growth_vs_ly_abs",
        "growth_vs_last_abs",
        "growth_vs_ly_pct",
        "growth_vs_last_pct",
        "national_growth_vs_ly_abs",
        "national_growth_vs_last_abs",
        "c2g_vs_ly",
        "c2g_vs_last",
    ]

    return optimize_dataframe_memory(agg[ordered_cols])

level_specs = [
    (["nestle_region", "nestle_store_cluster", "store_code", "product_code"], "SKU"),
    (["nestle_region", "nestle_store_cluster", "store_code"], "Store"),
    (["nestle_region", "nestle_store_cluster"], "Cluster"),
    (["nestle_region"], "Region"),
    ([], "National"),
]

level_frames = []
for group_cols, level_name in tqdm(level_specs, desc="Aggregating C2G levels", unit="level"):
    level_frames.append(aggregate_level(bottom_output, group_cols, level_name))

c2g_all_levels = optimize_dataframe_memory(pd.concat(level_frames, ignore_index=True))
del level_frames
gc.collect()

rank_scope = c2g_all_levels[c2g_all_levels["level"].isin(["SKU", "Store", "Cluster", "Region"])]

top_gainers = (
    rank_scope.sort_values(["ds", "level", "growth_vs_ly_abs"], ascending=[True, True, False])
    .groupby(["ds", "level"], as_index=False, observed=True)
    .head(10)
    .reset_index(drop=True)
)

top_decliners = (
    rank_scope.sort_values(["ds", "level", "growth_vs_ly_abs"], ascending=[True, True, True])
    .groupby(["ds", "level"], as_index=False, observed=True)
    .head(10)
    .reset_index(drop=True)
)

top_contributors = pd.concat(
    [
        top_gainers.assign(driver_direction="driver"),
        top_decliners.assign(driver_direction="detractor"),
    ],
    ignore_index=True,
).sort_values(["ds", "level", "driver_direction", "growth_vs_ly_abs"], ascending=[True, True, True, False])

db_frames = {
    "mart_c2g_bottom_level": bottom_output,
    "mart_c2g_all_levels": c2g_all_levels,
    "mart_c2g_top_gainers": top_gainers,
    "mart_c2g_top_decliners": top_decliners,
}

print_df_memory(c2g_all_levels, "All-levels output")
display(c2g_all_levels.head())


# ## 6) Save parquet outputs and run quick QA checks

# In[8]:


print("Skipped local parquet exports. Preparing DB tables only.")

qa = (
    c2g_all_levels.groupby(["ds", "level"], as_index=False, observed=True)[["c2g_vs_ly", "c2g_vs_last"]]
    .sum()
)

print("\nC2G shares by month/level (should be ~1.0 when national growth != 0):")
display(qa)

display_cols = [
    "ds", "level", "nestle_region", "nestle_store_cluster", "store_code",
    "product_code", "reconciled_forecast", "actual_ly", "growth_vs_ly_abs", "c2g_vs_ly"
]
print("\nSample output:")
display(c2g_all_levels[display_cols].head(20))


# ## 7) Export database tables
# 
# This section writes the C2G outputs to PostgreSQL using the same fast COPY-based helper used in the other notebooks.
# 

# In[9]:


db_frames["api_c2g_top_contributors"] = top_contributors
db_frames["api_c2g_drilldown"] = c2g_all_levels

overview_growth_drivers = (
    c2g_all_levels[c2g_all_levels["level"].isin(["Region", "Cluster", "SKU"])].copy()
    .sort_values(["ds", "growth_vs_ly_abs"], ascending=[True, False])
)
overview_growth_drivers["driver_direction"] = np.where(
    overview_growth_drivers["growth_vs_ly_abs"] >= 0,
    "driver",
    "detractor",
)
db_frames["api_overview_growth_drivers"] = optimize_dataframe_memory(overview_growth_drivers)

with tqdm(total=len(db_frames), desc="Saving outputs", unit="table") as pbar:
    for table_name, df in db_frames.items():
        write_df_fast(df, table_name)
        pbar.update(1)


# ## 8) Verification

# In[10]:


print("mart_c2g_bottom_level rows:", verify_table("mart_c2g_bottom_level"))
print("mart_c2g_all_levels rows:", verify_table("mart_c2g_all_levels"))
print("mart_c2g_top_gainers rows:", verify_table("mart_c2g_top_gainers"))
print("mart_c2g_top_decliners rows:", verify_table("mart_c2g_top_decliners"))
print("api_c2g_top_contributors rows:", verify_table("api_c2g_top_contributors"))
print("api_c2g_drilldown rows:", verify_table("api_c2g_drilldown"))


# ## 9) Optional in-notebook visualization
# 
# Run the next two cells only after the parquet exports above finish. This section is kept separate from the main C2G pipeline so the export path stays clean and readable.
# 

# In[11]:


print("api_overview_growth_drivers rows:", verify_table("api_overview_growth_drivers"))


# In[ ]:




