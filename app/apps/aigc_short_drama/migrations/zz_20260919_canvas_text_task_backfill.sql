-- Earlier canvas text submissions already contain the synchronous model output,
-- but were left as running when the provider response omitted an async status.
-- Promote only records with a non-empty persisted text result; failed/canceled
-- and genuinely pending work remain untouched.
UPDATE `la_aigc_short_drama_canvas_run`
SET `status` = 'success',
    `progress` = 100,
    `update_time` = UNIX_TIMESTAMP()
WHERE `node_type` = 'text'
  AND `status` = 'running'
  AND `delete_time` = 0
  AND JSON_VALID(`result_json`)
  AND COALESCE(
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(`result_json`), `result_json`, '{}'), '$.content')), ''),
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(`result_json`), `result_json`, '{}'), '$.text')), ''),
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(`result_json`), `result_json`, '{}'), '$.output')), '')
  ) IS NOT NULL;

-- Keep the tenant creation-task history in lockstep with the repaired canvas
-- runs so an already-completed text task is visible immediately after upgrade.
UPDATE `la_aigc_short_drama_generation_task` task
INNER JOIN `la_aigc_short_drama_canvas_run` run
    ON task.`tenant_id` = run.`tenant_id`
   AND task.`task_id` = CONCAT('canvas_run_', run.`id`)
SET task.`status` = 'success',
    task.`progress` = 100,
    task.`result_json` = run.`result_json`,
    task.`error_code` = '',
    task.`error_msg` = '',
    task.`safety_status` = 'passed',
    task.`finished_at` = IF(task.`finished_at` > 0, task.`finished_at`, UNIX_TIMESTAMP()),
    task.`update_time` = UNIX_TIMESTAMP()
WHERE run.`node_type` = 'text'
  AND run.`status` = 'success'
  AND run.`delete_time` = 0
  AND task.`task_type` = 'canvas_text'
  AND task.`delete_time` = 0
  AND JSON_VALID(run.`result_json`)
  AND COALESCE(
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(run.`result_json`), run.`result_json`, '{}'), '$.content')), ''),
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(run.`result_json`), run.`result_json`, '{}'), '$.text')), ''),
      NULLIF(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(run.`result_json`), run.`result_json`, '{}'), '$.output')), '')
  ) IS NOT NULL;
