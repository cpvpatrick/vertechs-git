<?php
// pulsekit/dashboard/_data.php
// Requires: include "../db.php" first.

function getProducts(mysqli $conn): array {
    $items = [];
    $res = $conn->query("SELECT product_id, product_description FROM dim_product ORDER BY product_description");
    while ($row = $res->fetch_assoc()) $items[] = $row;
    return $items;
}

function getRegions(mysqli $conn): array {
    $items = [];
    $res = $conn->query("SELECT DISTINCT nestle_region FROM dim_store WHERE nestle_region IS NOT NULL ORDER BY nestle_region");
    while ($row = $res->fetch_assoc()) $items[] = $row["nestle_region"];
    return $items;
}

function getClusters(mysqli $conn): array {
    $items = [];
    $res = $conn->query("SELECT DISTINCT nestle_store_cluster FROM dim_store WHERE nestle_store_cluster IS NOT NULL ORDER BY nestle_store_cluster");
    while ($row = $res->fetch_assoc()) $items[] = $row["nestle_store_cluster"];
    return $items;
}

function money($v): string {
    return number_format((float)$v, 2);
}