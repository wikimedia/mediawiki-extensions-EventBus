# Linked Artifacts

MediaWiki client for the Linked Artifacts Cache (LAC).

[Architectures#Linked Artifacts Cache](https://wikitech.wikimedia.org/wiki/Prep_Pantry/Architectures#Linked_Artifacts_Cache).

> This lives in EventBus for now, but will move to another extension.
> See [T432733](https://phabricator.wikimedia.org/T432733).

## What the LAC is

The LAC is a cache; its software is called *hoarde*. It stores values that other services
compute. Each value is an **artifact**, and each artifact is computed from a MediaWiki
**entity**, e.g. a page revision. LAC does not compute artifacts itself: it
calls a **lambda** function, then stores the result.

For the available artifacts, see the [LAC HTTP API docs](https://gitlab.wikimedia.org/repos/sre/hoarde/-/blob/main/API.md).

See also https://wikitech.wikimedia.org/wiki/Prep_Pantry/Architectures

## What this code does

NOTE: As of 2026-09, only revision entity linked artifacts are supported.
      As more entity types are needed, they will be added.
      Docs below only mention support for revision entity linked artifacts.

A lambda function can be slow, and the LAC stores an artifact only once a client asks for it. So
the first reader after an edit would wait for the computation. To avoid that, MediaWiki
asks the LAC for the artifact immediately after its entity changes (e.g. an edit).
We are calling this event triggered request a **prefetch**.

A prefetch is just a fetch ahead of expected reader use cases.
LAC returns the artifact if it is cached or consults the artifact lambda on a cache miss.
A prefetch for a reconciliation request (e.g. a null edit) sends
`Cache-Control: no-cache`, forcing LAC to consult the artifact lambda and store the result.

1. A user edits a page; MediaWiki emits `PageLatestRevisionChanged` DomainEvent.
2. `LinkedArtifactsPrefetchIngress` receives it. For each configured artifact that this
   event should trigger, a `linkedArtifactPrefetch.<artifact_name>` job is enqueued.
3. `LinkedArtifactPrefetchJob` runs and calls `LinkedArtifactsPrefetcher`,
   which GETs the artifact from LAC.
4. On success, `LinkedArtifactsPrefetcher` dispatches a `RevisionLinkedArtifactPrefetched`
   DomainEvent that carries the artifact content. The event timestamp is when LAC responded
   (the `Date` header). `getArtifactComputeTimestamp()` is when LAC computed the artifact
   (the `Last-Modified` header).

## The classes

| Class | Responsibility                                                                       |
| --- |--------------------------------------------------------------------------------------|
| `LinkedArtifactsConfig` | Interface for parsed and validated `$wgEventBusLinkedArtifacts` config.              |
| `LinkedArtifactsPrefetchIngress` | Maps input DomainEvents to prefetch jobs                                             |
| `LinkedArtifactPrefetchJob` | Runs one prefetch from its job params                                                |
| `LinkedArtifactsPrefetcher` | Prefetches one artifact and dispatches the LinkedArtifactPrefetchedEvent DomainEvent |
| `RevisionLinkedArtifactPrefetchedEvent` | DomainEvent dispatched after a revision artifact is prefetched                       |
| `LinkedArtifactsClient` | Client for the LAC HTTP API: locates and fetches artifacts. Callers should use this. |
| `LinkedArtifactResponse` | Represents a response from LAC, with the response body.                              |

NOTE: Singular class names represent or work with a single artifact,
      e.g. LinkedArtifactResponse, RevisionLinkedArtifactPrefetchedEvent, etc.
      Plural class names work with the LinkedArtifactsCache system, which is explicitly
      named 'Linked Artifacts Cache' -- 'Artifacts' plural.

## Subscribing to prefetched artifacts

Register a `DomainEventIngress` for `RevisionLinkedArtifactPrefetched` and implement
`RevisionLinkedArtifactPrefetchedListener`. The event is dispatched for every revision
artifact, so filter on `getArtifactName()`.

```json
"DomainEventIngresses": [
	{
		"class": "MyExtension\\MyArtifactIngress",
		"events": [ "RevisionLinkedArtifactPrefetched" ]
	}
]
```

The event fires only for prefetches that MediaWiki triggers. It does not fire for reader
fetches.

LAC can have computed the artifact for this prefetch, or it can have had it cached already.
The event timestamp is the time of the prefetch, and `getArtifactComputeTimestamp()` is when
LAC computed the artifact. `getTriggerTimestamp()` is when the trigger occurred, e.g. the edit.
LAC keeps a small number of revisions for each page, so a newer revision can make LAC remove
the artifact, even before the listeners run. A reader that needs a older revision artifact
later should store it (e.g. FlaggedRevs use cases).

## Reading a Revision linked artifact

Use `EventBus.LinkedArtifactsClient`.

```php
// Verify that this page should have this artifact.
if ( $client->coversPage( 'my-artifact', $wikiId, $pageId, $namespace ) ) {
	// Fetch a revision entity artifact from LAC.
	$response = $client->fetchRevisionArtifact( 'my-artifact', $wikiId, $pageId, $revisionId, $timeoutMs );
}
```

`coversPage()` is false when the feature is disabled or the artifact does not cover the
page.

By default, LAC consults the lambda to compute the artifact on a cache miss,
and the request waits for the lambda. To not wait, and not make LAC compute the artifact, pass
`LinkedArtifactsClient::CACHE_CONTROL_ONLY_IF_CACHED` as `$cacheControl`.
`LinkedArtifactResponse::isNotCached()` is then true on a miss.

`LinkedArtifactResponse::isNotFound()` means that LAC has no cache for the artifact name.
It does not mean a cache miss.

## Configuration

All configuration is in `$wgEventBusLinkedArtifacts`.

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | bool | `false` | Enable or disable LAC for this wiki. |
| `base_url` | string | null | LAC service base URL |
| `artifacts` | array | `{}` | One entry per artifact, keyed by LAC cache name |

Per artifact. `entity_kind` and `page.*` also govern reading; everything under `prefetch.*`
affects prefetch only:

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `entity_kind` | string | **required** | The kind of entity the artifact is linked to (and keyed by); selects which client method addresses it.  As of 2026-09, only 'revision' is supported. |
| `prefetch.enabled` | bool | `true` | Set false to stop prefetching this artifact, draining any queued jobs |
| `prefetch.events` | string[] | `[]` | Domain event types that trigger a prefetch |
| `prefetch.timeout_ms` | int | `LinkedArtifactsConfig::PREFETCH_TIMEOUT_MS_DEFAULT` | Timeout for the request from the prefetch job.|
| `page.*` | array | `{}` | page related artifact configuration.  For artifacts linked to both revision and page entities. |
| `page.namespaces` | int[] | all | Namespaces this artifact covers |
| `page.sample` | float | `1.0` | Fraction of pages covered, `0.0`–`1.0` |

`page.sample` and `page.namespaces` used by `LinkedArtifactsClient::coversPage()` to decide which pages an
artifact covers.

```php
$wgEventBusLinkedArtifacts = [
	'enabled' => true,
	'base_url' => 'https://linked-artifacts.discovery.wmnet:30443',
	'artifacts' => [
		'my_revision_artifact' => [
			'entity_kind' => 'revision',
			'prefetch' => [
				'events' => [ 'PageLatestRevisionChanged' ],
				'timeout_ms' => 30000,
			],
			'page' => [
				'namespaces' => [ 0 ],
				'sample' => 0.1,
			],
		],
	],
];
```

## Job queue

Each configured artifact has its own job type,
`linkedArtifactPrefetch.<artifact_name>`. All of these job types use `LinkedArtifactPrefetchJob`.
The job type is the job queue name, so each artifact has its own Kafka topic at WMF
(e.g. `eqiad.mediawiki.job.linkedArtifactPrefetch.<artifact_name>`). A slow lambda then does not delay
the prefetch of other artifacts.

`LinkedArtifactPrefetchJob::onRegistration()` (the extension registration callback) adds the job
types to `$wgJobClasses` from `$wgEventBusLinkedArtifacts`. No extension.json change is needed
for a new artifact.

At WMF, change-prop's `low_traffic_jobs` rule runs all `mediawiki.job.*` topics. To give an artifact
its own concurrency, add its job type to `high_traffic_jobs_config` in operations/deployment-charts.

## Metrics

The metrics use the StatsLib component name `linked_artifacts`. In Prometheus, the names
will start with `mediawiki_linked_artifacts_`.

All metrics have the labels `wiki_id`, `artifact`, `entity_kind` and `linked_artifact_service`.

| Metric | Type | Other labels | Notes |
| --- | --- | --- | --- |
| `fetch_duration_seconds` | Histogram | `cache_control`, `status_code`, `purpose` | Each LAC fetch, from readers and prefetches. `cache_control` is `none` when the request has no `Cache-Control`. `status_code` 0 is no response, e.g. a timeout. `purpose` is `read` or `prefetch`. The `_count` with `purpose="prefetch"` is the number of prefetches. |
| `fetch_artifact_body_size_bytes` | Histogram | `purpose` | The body size of each successful fetch. |
| `fetch_artifact_age_seconds` | Histogram | `cache_control`, `purpose` | `Date` minus `Last-Modified` of each successful fetch. Near 0: LAC computed the artifact for this request. |
| `prefetch_trigger_to_response_duration_seconds` | Histogram | `status_code` | The end-to-end duration of the prefetch: the time of the LAC response minus the time of the trigger (e.g. an edit). This includes the job queue delay and the LAC fetch. Prefetches without a trigger time are not observed. |

`LinkedArtifactsClient` defines the fetch histogram buckets.
`LinkedArtifactsPrefetcher` defines the `prefetch_trigger_to_response_duration_seconds` buckets.
Do not change the buckets of a deployed metric. Use a new metric name instead.

TODO: Add fetch Cache Status label after https://phabricator.wikimedia.org/T440993

Other metrics that are useful for prefetch:

- `mediawiki_EventBus_events_outgoing_by_stream_total{stream_name="mediawiki_job_linkedArtifactPrefetch_<artifact_name>"}`:
  the prefetch jobs that MediaWiki enqueues.
- `mediawiki_jobexecutor_exec_runtime_seconds{type="linkedArtifactPrefetch_<artifact_name>"}`:
  the run time of the prefetch jobs.
- The change-prop metrics of the job rule: the queue delay, the consumer lag and the retries.
