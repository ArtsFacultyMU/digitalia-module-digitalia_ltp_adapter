# Digitalia LTP adapter

Currently supports Archivematica and ARCLib.

## Operation overview
1. User in Islandora saves an object
2. The object is exported into a directory shared between Islandora and Archivematica
3. Object is added to queue to be ingested into Archivematica
4. When cron is run, all objects in queue are ingested

## Locking schema
When a SIP is created or to be ingested into LTP system, the program tries to lock the directory.

## Export structure
Export structure for Archivematica
```

local-devel-tkaniny_nid_229.zip
├── metadata
│   └── metadata.json
└── objects
    └── nid_229
        ├── cs.txt
        └── en.txt
 ```
