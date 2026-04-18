-- ============================================================
-- PulseKit Migration: Per-user pipeline execution tracking
-- Run this in phpMyAdmin or via MySQL CLI
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `pipeline_executed` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = user has run the full pipeline; unlocks all analytics modules'
    AFTER `dataset_loaded`;
