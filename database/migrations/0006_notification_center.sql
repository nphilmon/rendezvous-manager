ALTER TABLE sync_changes
  MODIFY entity_type ENUM('appointment','calendar','contact','category','notification') NOT NULL;

ALTER TABLE notification_deliveries
  ADD KEY idx_deliv_center (user_id, channel, status, sent_at);
