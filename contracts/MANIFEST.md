# contracts/ — AgentBadge Intel protocol contract

Single authoritative source: `hackathon/server/docs/EPICS/
262-agent-surface-intelligence/spec/` in the AgentBadge server repo.
This directory is a **verbatim copy** — PHP consumes the versioned
contract without Node (D-262-Q12). Do not edit here; regenerate via
`bin/sync-contracts.sh`. Verify: `bin/check-contracts.sh`.

Synced: 2026-11-24T16:32:50Z from /mnt/new-storage/projects/my/agents-ai/hedera/hackathon/server/docs/EPICS/262-agent-surface-intelligence/spec

## Checksums (sha256)

1a3cfe587d17597d1d9f99c9d85ae4d7706c207b0868e760cddef97db3dc1ec0  contracts/CONTRACT.md
235ed8a38968a474f19468a9ab5dec25049a18d76a8a662dd9986b333dc919c7  contracts/fixtures/envelopes/backend-verified.envelope.json
8ced9b2348e03e5afd2794311ee2afbbc3ae1b7102d3c3f25605c851fdf24483  contracts/fixtures/envelopes/derived.envelope.json
ec6f98e19c07ce1c06a520701f093b6d7c4483a4f64d5e714a0b19519569ec19  contracts/fixtures/envelopes/external-observed.envelope.json
94c0ab3948ebbcc68d9ea75455e43c094a177361695588e0bc9e42df986c2973  contracts/fixtures/envelopes/plugin-self-reported.envelope.json
ec4bffd38de90dec6e1b2b9ed635eb6e8a801acdcb68cfbd706b787ccf4aa48d  contracts/fixtures/legacy/agentbadge-report.json
ea6f53a1e5a6c43c1666fb244e25221de6af781707a5ea0046d679c364736db6  contracts/fixtures/negative/derived-without-from.envelope.json
25b03e593c8c9909d6b97b367f079923f9ba7853d5fec4d0e60ca6681ba51c3f  contracts/fixtures/negative/invalid-refs.submission.json
ef8c0574f6b5a5fbff5168e8968a77512452a388a9c8489c8c74cec820583b1b  contracts/fixtures/negative/invalid-signature.envelope.json
cf4e37ba9fadfdc51e5939095865906b5059d7589ac9466b3fb8b42a02b7c86c  contracts/fixtures/negative/spoofed-provenance.submission.json
28011099f8fff5fd9680fa24569593e5d46247a64ec2da24acf468ad8d7cb9f5  contracts/fixtures/negative/unknown-key-id.envelope.json
d707f3092a5893dea7c4ada6f4ea1dedcf5fce205cc6f0eeb396bbdbf3b53ab1  contracts/fixtures/negative/unknown-payload-type.submission.json
c14d069f47776ee3bf02178d6f8a35e17eb530643b3854957b50fe039a67d23c  contracts/fixtures/negative/unknown-schema-version.envelope.json
1dc87bc70e6e5e1f1565926d0e884661dc29311e56d5b3ff5c182da8f57a9ac4  contracts/pull-protocol/capability-registry.v1.json
cd4e874ee1dbf9763b684f6d12b23eff2ad096f0106ae87de29ce7440bea169f  contracts/pull-protocol/job.schema.json
c597b063067fe6c839514e8247ec9141df0c7cab0e40b38df4014c18ad82aa8e  contracts/pull-protocol/PROTOCOL.md
ff385ec198af3528c85b407612301fbaee19c6922bf63b6c95737958e062ab64  contracts/schemas/canonical-envelope.schema.json
2eb43ebda50ab35ce01b88a18771432cd3fbda7fd303c1d880df1681b9955a16  contracts/schemas/collector-submission.schema.json
