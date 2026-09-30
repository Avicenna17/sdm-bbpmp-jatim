<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:15360', 'mimes:xls,xlsx'],
        'directory' => 'livewire-tmp',
        'middleware' => 'throttle:30,1',
        'preview_mimes' => [],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
