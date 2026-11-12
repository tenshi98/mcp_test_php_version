-- 03_create_relations.sql  (claves foraneas)
ALTER TABLE `tokens`
  ADD CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `allowed_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `oauth_users`
  ADD CONSTRAINT `fk_oauth_users_user` FOREIGN KEY (`user_id`) REFERENCES `allowed_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `oauth_auth_codes`
  ADD CONSTRAINT `fk_oauth_codes_user` FOREIGN KEY (`user_id`) REFERENCES `allowed_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_oauth_codes_client` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`id`) ON DELETE CASCADE;

ALTER TABLE `oauth_access_tokens`
  ADD CONSTRAINT `fk_oauth_at_user` FOREIGN KEY (`user_id`) REFERENCES `allowed_users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_oauth_at_client` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`id`) ON DELETE CASCADE;

ALTER TABLE `oauth_refresh_tokens`
  ADD CONSTRAINT `fk_oauth_rt_access` FOREIGN KEY (`access_token_id`) REFERENCES `oauth_access_tokens` (`id`) ON DELETE CASCADE;

ALTER TABLE `security_events`
  ADD CONSTRAINT `fk_sec_events_user` FOREIGN KEY (`user_id`) REFERENCES `allowed_users` (`id`) ON DELETE SET NULL;
