-- Expand canvas run logs for streamed text and multi-image reference payloads.

ALTER TABLE `la_aigc_canvas_run`
  MODIFY COLUMN `params_json` longtext COMMENT '调用参数',
  MODIFY COLUMN `result_json` longtext COMMENT '执行结果';
