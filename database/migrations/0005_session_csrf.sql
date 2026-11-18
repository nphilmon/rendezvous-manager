ALTER TABLE user_sessions
  ADD COLUMN csrf_token CHAR(64) NOT NULL DEFAULT '' AFTER user_agent;
