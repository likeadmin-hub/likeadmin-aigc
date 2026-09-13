<?php

// Shared deterministic inputs for extraction baselines and request-assembly tests.
$subject = ['id' => 's1', 'name' => '林舟', 'category' => 'character', 'description' => '穿蓝色外套的青年'];
$location = ['id' => 'l1', 'name' => '书店', 'description' => '午后的安静书店'];
$shot = ['shot_id' => '1', 'scene_ref_id' => 'l1', 'scene_name' => '书店', 'subject_ref_ids' => ['s1'], 'visual_description' => '林舟打开一本旧书', 'recommended_duration_seconds' => 5, 'composition' => '中景', 'camera_movement' => '缓慢推近'];
$plan = ['subjects' => [$subject], 'locations' => [$location], 'storyboard' => [$shot]];
return [
    'single_system' => ['scriptPlanSystemPrompt', []],
    'multi_system' => ['multiEpisodeScriptPlanSystemPrompt', []],
    'character' => ['buildSubjectImagePrompt', [[], ['subject_id' => 's1'], $plan]],
    'character_three_view' => ['buildSubjectImagePrompt', [[], ['subject_id' => 's1', 'view_mode' => 'three_view'], $plan]],
    'prop' => ['buildSubjectImagePrompt', [[], ['subject_name' => '旧书', 'category' => 'prop'], []]],
    'prop_three_view' => ['buildSubjectImagePrompt', [[], ['subject_name' => '旧书', 'category' => 'prop', 'view_mode' => 'three_view'], []]],
    'scene' => ['buildSceneImagePrompt', [[], ['scene_id' => 'l1'], $plan]],
    'shot_image' => ['buildShotImagePrompt', [$shot, [], $plan]],
    'shot_video' => ['buildShotVideoPrompt', [$shot, ['duration' => 5, 'has_first_frame_image' => true, 'has_last_frame_image' => true], $plan]],
    'empty_video' => ['buildShotVideoPrompt', [array_replace($shot, ['subject_ref_ids' => [], 'shot_type' => '空镜', 'visual_description' => '阳光落在书架上']), ['duration' => 5], $plan]],
    'music' => ['normalizeMusicPlan', [[], [$shot], ['estimated_total_seconds' => 60], [], '青年寻找一本旧书']],
    'character_negative' => ['defaultSubjectNegativePrompt', [false]],
    'prop_negative' => ['defaultSubjectNegativePrompt', [true]],
    'scene_negative' => ['sceneGenerationNegativePrompt', [[]]],
    'video_negative' => ['shotVideoNegativePrompt', [$shot, false]],
];
