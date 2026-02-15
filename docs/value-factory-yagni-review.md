# ValueFactory YAGNI Review

Issue: `#1433`  
Date: 2026-02-15

## Scope

Evaluate whether the sub-factory composition in `src/Exif/Factory/ValueFactory.php` is still justified or should be inlined.

Assessed factories:

1. `CameraFactory`
2. `LensFactory`
3. `ExposureFactory`
4. `SensorFactory`
5. `DeviceFactory`
6. `ImageFactory`
7. `SceneFactory`
8. `MotionFactory`
9. `GpsFactory`
10. `TemporalFactory`
11. `RegionsFactory`
12. `MultiPictureFactory`

## Evaluation

### Complexity and responsibility

- Each factory encapsulates a distinct metadata domain and keeps `ValueFactory` orchestration-focused.
- `ImageFactory`, `SceneFactory`, `GpsFactory`, `TemporalFactory`, and `MultiPictureFactory` contain non-trivial fallback/normalization logic that would materially bloat `ValueFactory` if inlined.
- Splitting by domain keeps SRP/SoC boundaries explicit and reduces merge-conflict pressure in a large metadata surface.

### Reuse and testability

- The factory classes provide small, testable seams for domain-specific behavior.
- The existing constructor-injection setup allows targeted substitutions/mocks without introducing broad test fixtures.
- Keeping the classes separate avoids creating one large method with multiple hidden condition branches.

### Runtime overhead

- Instantiation overhead is negligible compared to metadata parsing and decoding costs.
- The current object graph is simple and immutable enough that eager construction is acceptable.
- No measurable bottleneck evidence currently justifies a structural flattening.

## Decision

Keep the 12 sub-factories as-is for now.

Rationale:

1. Removing them would reduce local indirection but increase central coupling and method size in `ValueFactory`.
2. The current split is aligned with SRP/SoC and preserves maintainability in ongoing EXIF/XMP/QuickTime work.
3. No concrete performance evidence indicates that inlining would provide meaningful gains.

## Revisit criteria

Re-open this decision if one of the following happens:

1. Profiling shows constructor churn from these factories in hot paths.
2. A majority of factories become thin pass-through wrappers with no domain logic.
3. New architecture constraints require reducing object count for embedded/runtime-limited targets.
