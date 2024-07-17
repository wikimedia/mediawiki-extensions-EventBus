# EventBus

EventBus is a [MediaWiki](https://www.mediawiki.org/) extension that
produces changes to an [event intake service].

[event_intake_service]: https://wikitech.wikimedia.org/wiki/Event_Platform/EventGate

This extension is part of a larger effort to create a general purpose event
system, to reliably propagate state changes from one part of the
infrastructure, to another. Since this approach relies upon MediaWiki
Hooks and deferred (async) PHP function calls, it is not atomic (delivery
of an event could fail after MediaWiki has committed the corresponding
change), and so does not provide the reliability we're aiming for.
Therefore, this extension should be considered an interim solution, until
the requisite changes can be made to MediaWiki core. See [this task] for
more information.

[this task]: https://phabricator.wikimedia.org/T120242

This EventBus extension is really meant to be just a Wikimedia Event
Platform producer client library for MediaWiki PHP, but over time it has
also become a place where the logic for constructing events themselves is
placed.

EventBus was originally intended just for the propagation of changes to
core MediaWiki entities (pages, revisions, users, etc.). Until a better
place becomes available, or MediaWiki core provides us with a default way
to do this, event construction logic for MediaWiki core entities should
remain here in this EventBus extension.

However, other usages of EventBus as a library to produce events should
live in other extensions that use the EventBus extension as a dependency.

## Configuration

### Event Services definition

EventBus supports configuration of multiple event intake service endpoints
via the `$wgEventServices` main config array. It expects entries keyed by
event service name pointing at arrays of event service config. E.g.

```php
$wgEventServices = [
    'eventgate-analytics' => [
        'url' => 'http://locahost:8085/v1/events',
        'timeout' => 5,
    ],
    'eventgate-main' => [
        'url' => 'http://localhost:8192/v1/events',
    ],
];
```

EventBus instances should be created via either the static
`EventBusFactory::getInstance` or `EventBusFactory::getInstanceForStream`
methods.

`EventBusFactory::getInstance` takes one of the configured event service
names from the `EventServices` main config.

`EventBusFactory::getInstanceForStream` takes a stream name and looks up
the event service name configured for that stream in `$wgEventStreams`
config array, via the [EventStreamConfig] extension (see below).

[EventStreamConfig]: https://wikitech.wikimedia.org/wiki/Event_Platform/Stream_Configuration

### Stream specific settings for EventBus

EventBus also supports some per stream configuration. These settings are
defined in the `producers.mediawiki_eventbus` stream config setting for a
stream.


#### Destination Event Intake servive

The event service name setting (and other settings for EventBus) for a
stream is defined in the `producers.mediawiki_eventbus.event_service_name`
stream config setting. E.g. for a stream named 'my_stream':

```php
$wgEventStreams = [
    'my_stream' => [
        'producers' => [
            // EventBus specific settings go here:
            'mediawiki_eventbus' => [
                // Key of the event service in EventServices
                'event_service_name' => 'eventgate-main'
            ],
        ],
    ],
];
```
NOTE: Until
[T321557 - EventBus' stream config destination_event_service setting should move into producers.mediawiki_eventbus specific settings](https://phabricator.wikimedia.org/T321557)
is complete, a legacy top level `destination_event_service` setting to look
up the event service name in `$wgEventServices` is still supported.

Per stream configuration of the event intake service to use via
EventStreamConfig is optional. The default behavior is to produce all
streams to the service name specified by `$wgEventServiceDefault`. You
must set `$wgEventServiceDefault` to an entry in `$wgEventServices` to be
used in case a stream's `producers.mediawiki_eventbus.event_service_name`
setting is not provided. E.g.

```php
$wgEventServiceDefault = 'eventgate-main';
```

#### Enabling/disabling EventBus stream producing

If EventStreamConfig is not enabled (`$wgEventStreams` is undefined), then
all streams are considered 'enabled' and will be produced to the
`$wgEventServiceDefault`.


If EventStreamConfig is enabled, then all declared streams are enabled by
default and will be produced by `EventBus` instances to the destination
event intake service as defined above.

You can disable a stream by removing its entry in `$wgEventStreams`, or by
by setting `producers.mediawiki_eventbus.enabled = false` , e.g.
```php
$wgEventStreams = [
    'my_stream' => [
        'producers' => [
            // EventBus specific settings go here:
            'mediawiki_eventbus' => [
                // Key of the event service in EventServices
                'event_service_name' => 'eventgate-main',
                // Disable this stream
                'enabled' => false,
            ],
        ],
    ],
];
```

In either case, EventBus will not produce events to a stream that is not enabled.

### EnableEventBus

`$wgEnableEventBus` is an extension wide config parameter specifies which
types of events the extension will produce. Possible options are
`TYPE_NONE`, `TYPE_EVENT`, `TYPE_JOB`, `TYPE_PURGE` or `TYPE_ALL`.
Specifying multiple types using `|` as a delimiter is supported. Example:
`TYPE_JOB|TYPE_PURGE`

This setting can be used to globally restrict the 'types' of events the
extension can produce. E.g. if you want to fully disable producing real
events, perhaps in testing environments, you can set `$wgEnableEventBus =
"TYPE_NONE"`.

### EventBusStreamNamesMap config

HookHandlers/MediaWiki/PageChangeHooks.php adds a config
`EventBusStreamNamesMap` that maps from internal logical names of streams
to the actual stream name that will be produced. This allows developers to
vary the stream name used for e.g. 'mediawiki.page_change' in testing and
staging environments. Perhaps you want to produce page change events to a
release candidate stream before promoting it to 'production'. Instead of
having 'mediawiki.page_change.v1' hardcoded into the hook handler, the
stream name to produce will be looked up in config from
`EventBusStreamNamesMap['mediawiki_page_change']`, defaulting to
'mediawiki.page_change.v1'.

Any HookHandlers that produce events should support configuring the stream
name that they produce to using `EventBusStreamNamesMap` in this way.

TODO: The logic to look up the stream name to produce should be DRYed into
its own location the next time we add a new HookHandler here.


## EventBus HookHandlers and generated events

As noted above, this EventBus extension should probably be just a producer
library. At the very least, events that are not about carrying MediaWiki
core state to external systems shouldn't be here.

For the time being though, this EventBus extension contains several
MediaWiki hook handlers that actually produce events.

`EventBusHooks` and `EventFactory` are deprecated in favor of HookHandler
classes and specific Serializers that serialize from MediaWiki classes to
event objects.

Each single stream that is produced by this extension should have its own
HookHandler class that is solely responsible for producing events to that
stream.


## EventBus RCFeed

This extension also provides an FormattedRCFeed and RCFeedFormatter
implementation That will allow RCFeed configuration to post to the EventBus
service in the `mediawiki.recentchange` topic. To use, add the following
to your `LocalSettings.php`:

```php
use MediaWiki\Extension\EventBus\Adapters\RCFeed\EventBusRCFeedEngine;
use MediaWiki\Extension\EventBus\Adapters\RCFeed\EventBusRCFeedFormatter;

$wgRCFeeds['eventgate-main'] = array(
    'class'            => EventBusRCFeedEngine::class,
    'formatter'        => EventBusRCFeedFormatter::class,
    // This should be the name of an event service entry
    // defined in $wgEventServices.
    'eventServiceName' => 'eventgate-main',
);
```

## References

  * Wikimedia Foundation's [Event Platform](https://wikitech.wikimedia.org/wiki/Event_Platform)
  * [Reliable publish / subscribe event bus](https://phabricator.wikimedia.org/T84923)
  * [Integrate event production into MediaWiki](https://phabricator.wikimedia.org/T116786)

## License

EventBus is licensed under the GNU General Public License 2.0 or any later
version. You may obtain a copy of this license at <http://www.gnu.org/copyleft/gpl.html>.
