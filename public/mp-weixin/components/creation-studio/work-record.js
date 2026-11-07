"use strict";exports.mergeWorkDetail=function(i,t,d){const s={...i,...t};return d&&(s.id=i.id,s.result_id=i.result_id||i.id,s.task_id=t.task_id||t.id||i.task_id),s};
