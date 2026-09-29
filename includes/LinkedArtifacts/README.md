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
We are calling this event triggered request a **precompute**.

1. A user edits a page; MediaWiki emits `PageLatestRevisionChanged` DomainEvent.
2. `LinkedArtifactsPrecomputeIngress` receives it. For each configured artifact that this
   event should trigger, a `linkedArtifactPrecompute` job is enqueued.
3. `LinkedArtifactPrecomputeJob` runs and calls `LinkedArtifactsPrecompute`,
   which GETs the artifact from LAC with `Cache-Control: no-cache`, causing LAC to consult the lambda and store the result.
4. On success, `LinkedArtifactsPrecompute` dispatches a `RevisionLinkedArtifactPrecomputed`
   DomainEvent that carries the artifact content.

## The classes

| Class | Responsibility                                                          |
| --- |-------------------------------------------------------------------------|
| `LinkedArtifactsConfig` | Interface for parsed and validated `$wgEventBusLinkedArtifacts` config. |
| `LinkedArtifactsPrecomputeIngress` | Maps input DomainEvents to precompute jobs                              |
| `LinkedArtifactPrecomputeJob` | Runs one precompute from its job params                                 |
| `LinkedArtifactsPrecompute` | Force-refreshes one artifact and dispatches the precomputed DomainEvent |
| `RevisionLinkedArtifactPrecomputedEvent` | DomainEvent dispatched after a revision artifact is precomputed         |
| `LinkedArtifactsClient` | Client for the LAC HTTP API: locates and fetches artifacts. Callers should use this. |
| `LinkedArtifactResponse` | Represents a response from LAC, with the response body.                 |

The precompute job carries the artifact name, the entity kind and the entity identifiers
(`wiki_id`, `page_id`, `revision_id`). It also carries what triggered the precompute. The
trigger is part of job deduplication, because the precomputed DomainEvent carries it.

NOTE: Singular class names represent or work with a single artifact,
      e.g. LinkedArtifactResponse, RevisionLinkedArtifactPrecomputeEvent, etc.
      Plural class names work with the LinkedArtifactsCache system, which is explicitly
      named 'Linked Artifacts Cache' -- 'Artifacts' plural.

## Subscribing to precomputed artifacts

Register a `DomainEventIngress` for `RevisionLinkedArtifactPrecomputed` and implement
`RevisionLinkedArtifactPrecomputedListener`. The event is dispatched for every revision
artifact, so filter on `getArtifactName()`.

```json
"DomainEventIngresses": [
	{
		"class": "MyExtension\\MyArtifactIngress",
		"events": [ "RevisionLinkedArtifactPrecomputed" ]
	}
]
```

The event fires only for precomputes that MediaWiki triggers. It does not fire when LAC
computes an artifact on a read miss, or for pages outside the artifact's `page.*` coverage.

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

By default, LAC computes the artifact on a cache miss, and the request waits for the lambda.
To not wait, and not make LAC compute the artifact, pass
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

Per artifact. `entity_kind` and `page.*` also govern reading; everything under `precompute.*`
affects precompute only:

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `entity_kind` | string | **required** | The kind of entity the artifact is linked to (and keyed by); selects which client method addresses it.  As of 2026-09, only 'revision' is supported. |
| `precompute.enabled` | bool | `true` | Set false to stop precomputing this artifact, draining any queued jobs |
| `precompute.events` | string[] | `[]` | Domain event types that trigger a precompute |
| `precompute.timeout_ms` | int | `LinkedArtifactsConfig::PRECOMPUTE_TIMEOUT_MS_DEFAULT` | Timeout for the no-cache request from the precompute job.|
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
			'precompute' => [
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
