# Digitalia LTP adapter

## TODO list
- Add 'deleted' flag to objects deletd in Islandora
- ~~Solve possible race conditions when processing queue items and saving Isladora objects~~ the window is greatly reduced, if not removed

## Operation overview
1. User in Islandora saves an object
2. The object is exported into a directory shared between Islandora and Archivematica
3. Object is added to queue to be ingested into Archivematica
4. When cron is run, all objects in queue are ingested into Archivematica

## Locking schema
Upon entering the `archiveSourceEntity` function an attempt to lock the directory is executed. If successfull, the potential queue worker either finds a lock and waits, otherwise the save process waits until the transfer ended. Even if both processes (save and queue worker) somehow obtain lock and both procede, queue worker checks for `metadata.json`, which is copied after everything else has been copied to the directory. Writing of `metadata.json` should be atomic (uses `rename()` function).

## Terminology

```
public://archivematica/archivematica/local-devel-tkaniny_mid_3/objects/mid_3/003_2020_06_29.jpg
```
- Base directory URL is `public://archivematica/archivematica`
- Base object directory is `local-devel-tkaniny_mid_3`
- URL of object directory is `public://archivematica/archivematica/local-devel-tkaniny_mid_3`
- Base path is `objects/mid_3`
- Filename is `003_2020_06_29.jpg`



## Export structure
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
