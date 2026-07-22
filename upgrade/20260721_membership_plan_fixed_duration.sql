SET @membership_plan_duration_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `la_membership_plan` ADD COLUMN `duration_months` int unsigned NOT NULL DEFAULT 1 COMMENT ''有效月数'' AFTER `description`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_membership_plan'
    AND COLUMN_NAME = 'duration_months'
);
PREPARE membership_plan_duration_stmt FROM @membership_plan_duration_sql;
EXECUTE membership_plan_duration_stmt;
DEALLOCATE PREPARE membership_plan_duration_stmt;

UPDATE `la_membership_plan`
SET `duration_months` = 1
WHERE `duration_months` = 0;

UPDATE `la_membership_plan`
SET
  `yearly_price` = `monthly_price`,
  `yearly_market_price` = `monthly_market_price`,
  `yearly_bonus_points` = `monthly_bonus_points`,
  `features` = '["开通赠送100积分","会员有效期1个月","适合个人轻量创作"]',
  `update_time` = UNIX_TIMESTAMP()
WHERE `name` = '基础会员'
  AND `monthly_bonus_points` = 100.00;

UPDATE `la_membership_plan`
SET
  `yearly_price` = `monthly_price`,
  `yearly_market_price` = `monthly_market_price`,
  `yearly_bonus_points` = `monthly_bonus_points`,
  `features` = '["开通赠送300积分","会员有效期1个月","适合高频图文与视频创作"]',
  `update_time` = UNIX_TIMESTAMP()
WHERE `name` = '高级会员'
  AND `monthly_bonus_points` = 300.00;
