# Contributing to Formhawk

Read [AGENTS.md](AGENTS.md) before changing code. It defines the architecture, privacy contract, compatibility requirements and review criteria.

1. Branch from `master` and keep each change focused.
2. Inspect the affected implementation and tests. Preserve existing data and metric semantics.
3. Add tests appropriate to the risk. Verify provider hooks against official documentation or installed source.
4. Edit source assets in `resources/`, run `npm run build`, and include corresponding `assets/` changes.
5. Run the checks documented in [README.md](README.md). Use a disposable WordPress database for PHP tests.
6. Open a pull request describing the problem, resulting behavior, validation performed and any limitations.

Do not commit credentials, visitor data, database dumps, dependencies, IDE state or local build archives. Changes to data collection or persistent identifiers require explicit architectural approval.

For reproducible bug reports, include WordPress, PHP, Formhawk and provider versions, steps to reproduce and expected/actual behavior. Remove credentials and submitted form values from all attachments. Do not publish exploitable security details or secrets in public issues.

GitHub is the development repository. WordPress.org publication is a separate release workflow with the gates in AGENTS.md.
