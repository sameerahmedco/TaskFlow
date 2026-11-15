-- ============================================================
-- Verify everything is in place — run this and every row should
-- say "OK". Anything saying "MISSING" means that step above
-- didn't take effect yet.
-- ============================================================
SELECT 'weekly_priorities table' AS check_item,
  IF(COUNT(*) > 0, 'OK', 'MISSING') AS status
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'weekly_priorities'
UNION ALL
SELECT 'user_roles table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_roles'
UNION ALL
SELECT 'chat_messages table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'chat_messages'
UNION ALL
SELECT 'chat_messages.scope column', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND column_name = 'scope'
UNION ALL
SELECT 'chat_mentions table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'chat_mentions'
UNION ALL
SELECT 'app_settings table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'app_settings'
UNION ALL
SELECT 'direct_messages table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'direct_messages'
UNION ALL
SELECT 'user_notification_prefs table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_notification_prefs'
UNION ALL
SELECT 'app_settings.notify_new_message column', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'app_settings' AND column_name = 'notify_new_message'
UNION ALL
SELECT 'remember_tokens table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'remember_tokens'
UNION ALL
SELECT 'user_notification_prefs.browser_enabled column', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_notification_prefs' AND column_name = 'browser_enabled'
UNION ALL
SELECT 'notification_log table', IF(COUNT(*) > 0, 'OK', 'MISSING')
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'notification_log';
