# Digitalia LTP adapter

## TODO list
- Add 'deleted' flag to objects deletd in Islandora
- Solve possible race conditions when processing queue items and saving Isladora objects

## Operation overview
1. User in Islandora saves an object
2. The object is exported into a directory shared between Islandora and Archivematica
3. Object is added to queue to be ingested into Archivematica
4. When cron is run, all objects in queue are ingested into Archivematica

## Terminology

```
public://archivematica/archivematica/local-devel-tkaniny_mid_3/objects/mid_3/003_2020_06_29.jpg
```
- Base directory URL is `public://archivematica/archivematica`
- Base object directory is `local-devel-tkaniny_mid_3`
- URL of object directory is `public://archivematica/archivematica/local-devel-tkaniny_mid_3`
- Base path is `objects/mid_3`
- Filename is `003_2020_06_29.jpg`



## Export modes
### Separate
This mode can be chosen using provided block
```
.
├── local-devel-tkaniny_nid_229
│   ├── metadata
│   │   └── metadata.json
│   └── objects
│       └── nid_229
│           ├── cs.txt
│           └── en.txt
└── local-devel-tkaniny_nid_231
    ├── metadata
    │   └── metadata.json
    └── objects
        └── nid_231
            ├── cs.txt
            └── en.txt
 ```


### Single
Default mode when saving/deleting object
```
.
└── local-devel-tkaniny_nid_229
    ├── metadata
    │   └── metadata.json
    └── objects
        └── nid_229
            ├── cs.txt
            └── en.txt
 ```
