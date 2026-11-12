-- 02_create_indexes.sql
ALTER TABLE `allowed_users`
  ADD UNIQUE KEY `uq_allowed_users_email` (`email`),
  ADD KEY `idx_allowed_users_status` (`status`);

ALTER TABLE `tokens`
  ADD UNIQUE KEY `uq_tokens_hash` (`token_hash`),
  ADD KEY `idx_tokens_user` (`user_id`),
  ADD KEY `idx_tokens_status_expiry` (`status`, `expires_at`);

ALTER TABLE `oauth_users`
  ADD UNIQUE KEY `uq_oauth_users_identity` (`provider`, `external_id`),
  ADD KEY `idx_oauth_users_user` (`user_id`),
  ADD KEY `idx_oauth_users_email` (`email`);

ALTER TABLE `oauth_auth_codes`
  ADD KEY `idx_oauth_codes_user` (`user_id`),
  ADD KEY `idx_oauth_codes_client` (`client_id`);

ALTER TABLE `oauth_access_tokens`
  ADD KEY `idx_oauth_at_user` (`user_id`),
  ADD KEY `idx_oauth_at_client` (`client_id`),
  ADD KEY `idx_oauth_at_expiry` (`expires_at`);

ALTER TABLE `oauth_refresh_tokens`
  ADD KEY `idx_oauth_rt_access` (`access_token_id`),
  ADD KEY `idx_oauth_rt_expiry` (`expires_at`);

ALTER TABLE `security_events`
  ADD KEY `idx_sec_events_type` (`event_type`),
  ADD KEY `idx_sec_events_user` (`user_id`),
  ADD KEY `idx_sec_events_created` (`created_at`);

ALTER TABLE `sample_records`
  ADD UNIQUE KEY `uq_sample_records_email` (`email`),
  ADD KEY `idx_sample_last_first` (`last_name`, `first_name`),
  ADD KEY `idx_sample_category` (`category`),
  ADD KEY `idx_sample_status` (`status`),
  ADD KEY `idx_sample_event_date` (`event_date`),
  ADD KEY `idx_sample_price` (`price`),
  ADD KEY `idx_sample_created` (`created_at`);
