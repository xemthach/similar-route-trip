# Release Flow

Use this flow for every plugin update so versioning, GitHub pushes, and changelog entries stay consistent.

## 1. Prepare

1. Update code.
2. Run lint and smoke tests.
3. Bump version in `similar-route-trip.php`.
4. Add a changelog entry in `CHANGELOG.md`.

## 2. Commit

```bash
git status --short
git add .
git commit -m "Release Similar Route Trip x.y.z"
```

## 3. Tag

```bash
git tag -a vx.y.z -m "Similar Route Trip x.y.z"
```

## 4. Push

```bash
git push origin main
git push origin vx.y.z
```

## 5. GitHub Release Notes

Use this format in the GitHub release body:

```md
## Highlights
- ...
- ...

## QA
- Lint: pass
- Preview: pass
- Create post: pass
- Queue: pass

## Notes
- Any follow-up work
```

## 6. Quick Checklist

- [ ] Version bumped
- [ ] Changelog updated
- [ ] Lint passed
- [ ] Regression smoke test passed
- [ ] Commit created
- [ ] Tag created
- [ ] Pushed to GitHub
