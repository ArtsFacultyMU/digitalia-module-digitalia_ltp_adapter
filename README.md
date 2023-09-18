# Digitalia LTP adapter

## Terminology

```
public://archivematica/archivematica/Růžový lampas s krajkovou bordurou obepnutou exotickou květenou/objects/Růžový lampas s krajkovou bordurou obepnutou exotickou květenou/001_2020_06_29.jpg
```
- Base directory URL is `public://archivematica/archivematica`
- Base object directory is `Růžový lampas s krajkovou bordurou obepnutou exotickou květenou` (the first one)
- URL of object directory is `public://archivematica/archivematica/Růžový lampas s krajkovou bordurou obepnutou exotickou květenou`
- Base path is `objects/Růžový lampas s krajkovou bordurou obepnutou exotickou květenou`
- Filename is `001_2020_06_29.jpg`



## Export modes
### Separate
```
.
├── Růžový lampas s krajkovou bordurou obepnutou exotickou květenou
│   ├── metadata
│   │   └── metadata.json
│   └── objects
│       └── Růžový lampas s krajkovou bordurou obepnutou exotickou květenou
│           ├── 001_2020_06_29.jpg
│           ├── 001_2020_06_29.png
│           ├── 001_2020_06_29.psd
│           ├── 001_2020_06_29.tif
│           ├── cs.txt
│           └── en.txt
└── Zelený damašek s exotickým keřem a dvojicí stříbrných bordur
    ├── metadata
    │   └── metadata.json
    └── objects
        └── Zelený damašek s exotickým keřem a dvojicí stříbrných bordur
            ├── 003_2020_06_29.jpg
            ├── 003_2020_06_29.png
            ├── 003_2020_06_29.psd
            ├── 003_2020_06_29.tif
            ├── cs.txt
            └── en.txt
 ```


### Tree
```
.
└── Růžový lampas s krajkovou bordurou obepnutou exotickou květenou
    ├── metadata
    │   └── metadata.json
    └── objects
        └── Růžový lampas s krajkovou bordurou obepnutou exotickou květenou
            ├── 001_2020_06_29.jpg
            ├── 001_2020_06_29.png
            ├── 001_2020_06_29.psd
            ├── 001_2020_06_29.tif
            ├── Zelený damašek s exotickým keřem a dvojicí stříbrných bordur
            │   ├── 003_2020_06_29.jpg
            │   ├── 003_2020_06_29.png
            │   ├── 003_2020_06_29.psd
            │   ├── 003_2020_06_29.tif
            │   ├── cs.txt
            │   └── en.txt
            ├── cs.txt
            └── en.txt
```


### Flat
```
.
└── Růžový lampas s krajkovou bordurou obepnutou exotickou květenou
    ├── metadata
    │   └── metadata.json
    └── objects
        ├── Růžový lampas s krajkovou bordurou obepnutou exotickou květenou
        │   ├── 001_2020_06_29.jpg
        │   ├── 001_2020_06_29.png
        │   ├── 001_2020_06_29.psd
        │   ├── 001_2020_06_29.tif
        │   ├── cs.txt
        │   └── en.txt
        └── Zelený damašek s exotickým keřem a dvojicí stříbrných bordur
            ├── 003_2020_06_29.jpg
            ├── 003_2020_06_29.png
            ├── 003_2020_06_29.psd
            ├── 003_2020_06_29.tif
            ├── cs.txt
            └── en.txt
```

### Single
```
.
└── Růžový lampas s krajkovou bordurou obepnutou exotickou květenou
    ├── metadata
    │   └── metadata.json
    └── objects
        └── Růžový lampas s krajkovou bordurou obepnutou exotickou květenou
            ├── 001_2020_06_29.jpg
            ├── 001_2020_06_29.png
            ├── 001_2020_06_29.psd
            ├── 001_2020_06_29.tif
            ├── cs.txt
            └── en.txt
 ```
