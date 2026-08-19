-- Group image and video generation records under the tenant task log menu.
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT source.`tenant_id`,0,'M','任务日志','el-icon-Document',50,'','task-log','','','',0,1,0,'','core','core_task_log_tenant',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
  SELECT DISTINCT `tenant_id`
  FROM `la_tenant_system_menu`
  WHERE `source_menu_key` IN ('aigc_image_task','aigc_video_task')
) source
WHERE NOT EXISTS (
  SELECT 1
  FROM `la_tenant_system_menu` existing
  WHERE existing.`tenant_id`=source.`tenant_id`
    AND existing.`source_menu_key`='core_task_log_tenant'
);

UPDATE `la_tenant_system_menu` application
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id`=application.`tenant_id`
 AND parent.`source_menu_key`='core_task_log_tenant'
SET application.`pid`=parent.`id`,
    application.`name`='应用日志',
    application.`sort`=100,
    application.`paths`='application',
    application.`update_time`=UNIX_TIMESTAMP()
WHERE application.`source_menu_key`='core_ai_task_tenant';

UPDATE `la_tenant_system_menu` image_task
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id`=image_task.`tenant_id`
 AND parent.`source_menu_key`='core_task_log_tenant'
SET image_task.`pid`=parent.`id`,
    image_task.`name`='生图列表',
    image_task.`sort`=90,
    image_task.`paths`='image',
    image_task.`update_time`=UNIX_TIMESTAMP()
WHERE image_task.`source_menu_key`='aigc_image_task';

UPDATE `la_tenant_system_menu` video_task
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id`=video_task.`tenant_id`
 AND parent.`source_menu_key`='core_task_log_tenant'
SET video_task.`pid`=parent.`id`,
    video_task.`name`='视频列表',
    video_task.`sort`=80,
    video_task.`paths`='video',
    video_task.`update_time`=UNIX_TIMESTAMP()
WHERE video_task.`source_menu_key`='aigc_video_task';

UPDATE `la_tenant_system_menu` consumption
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id`=consumption.`tenant_id`
 AND parent.`source_menu_key`='core_task_log_tenant'
SET consumption.`pid`=parent.`id`,
    consumption.`name`='消耗日志',
    consumption.`sort`=70,
    consumption.`paths`='consumption',
    consumption.`update_time`=UNIX_TIMESTAMP()
WHERE consumption.`source_menu_key`='core_ai_consumption_tenant';

-- A role that already owns either task list also needs its new parent menu.
INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role_menu.`role_id`,parent.`id`
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` task_menu
  ON task_menu.`id`=role_menu.`menu_id`
 AND task_menu.`source_menu_key` IN ('aigc_image_task','aigc_video_task')
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id`=task_menu.`tenant_id`
 AND parent.`source_menu_key`='core_task_log_tenant';
