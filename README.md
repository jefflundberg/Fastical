# Fastical

A fast PHP ical parser for vevents.

## Requirements
 * PHP > 5.5
 * Composer

## Setup
In composer.json:
```
{
    "require": {
        "jefflundberg/fastical": "dev-master"
    }
}
```

## Usage

Get events from the next 7 days:
```
$f = new Fastical('events.ics');
print_r($f->getEvents(time(), time() + 7 * 24 * 60 * 60));
```
