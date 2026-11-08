PC entry: /ai/tools/aigc_person_replacement

Tenant admin entries:
- /aigc-person-replacement/config
- /aigc-person-replacement/task

Missing covers on successful historical works can be repaired without querying
the provider, generating another video, or changing billing. The command uses
the saved response, validates the image, and persists it to the work's stored
storage scope and engine. It updates only the missing cover and update time;
existing covers and deleted/unfinished works are excluded.

```sh
php think person-replacement:repair-covers --tenant-id=TENANT_ID
php think person-replacement:repair-covers --tenant-id=TENANT_ID --apply
```

Optional `--user-id`, `--result-id`, and `--limit` narrow the scope. The default
is audit only. A repeat application skips repaired works. Missing saved covers,
unavailable source images, or changed storage configurations are reported and
leave the video and its billing untouched.
