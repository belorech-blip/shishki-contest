-- Migration to align existing DB with the working GitHub version.
-- If a statement says the column/key already exists, skip that statement and run the next one.

ALTER TABLE `admins`
  ADD `token` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `password_hash`;

ALTER TABLE `admins`
  ADD KEY `idx_admin_token` (`token`);

ALTER TABLE `subscriptions`
  ADD `telegram_agents_status` enum('not_requested','pending','confirmed','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_requested' AFTER `telegram_status`;

ALTER TABLE `tickets`
  MODIFY `reason` enum('deal','publications_30','socials_5','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual';

ALTER TABLE `prizes`
  MODIFY `prize_type` enum('fuel_20','fuel_30','ozon_10000','ozon_20000','ozon_30000','plot','manual') COLLATE utf8mb4_unicode_ci NOT NULL;
