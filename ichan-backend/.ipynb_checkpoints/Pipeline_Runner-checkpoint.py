from tqdm import tqdm
import time

from Cleaning import run_cleaning
from FE import run_fe
from MSTL import run_mstl
from LightGBM import run_lightgbm
from MinT import run_mint
from C2G import run_c2g
from Prescription import run_prescription
from Finalize_APIs import run_finalize_apis

steps = [
    ("Cleaning", run_cleaning),
    ("Feature Engineering", run_fe),
    ("MSTL", run_mstl),
    ("LightGBM", run_lightgbm),
    ("MinT", run_mint),
    ("C2G", run_c2g),
    ("Prescription", run_prescription),
    ("Finalize APIs", run_finalize_apis),
]

with tqdm(total=len(steps), desc="Retail Pipeline", unit="step", dynamic_ncols=True) as pbar:
    for name, func in steps:
        start = time.time()
        print(f"\n=== RUNNING {name.upper()} ===")
        func()
        elapsed = time.time() - start
        print(f" {name} completed in {elapsed:.2f}s")
        pbar.update(1)

print("\n PIPELINE COMPLETE")
