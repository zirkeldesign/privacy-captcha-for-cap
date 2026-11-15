---
name: fluent-forms-integration
description: Maintain, debug, test, or update this plugin's Fluent Forms integration, including compatibility with new Fluent Forms or WordPress versions and its CI fixture.
---

# Fluent Forms Integration

1. Read `docs/fluent-forms-integration.md` in the repository root before changing the integration. It is the shared source of truth for the hook contracts, limitations, test setup, and version-update checklist.
2. Trace the relevant behavior through `src/Integration/FluentForms.php`, `assets/js/fluent-forms.js`, and the matching tests. Check the actual installed Fluent Forms version when a host-plugin contract is in doubt; do not assume a source checkout is an installable plugin.
3. Keep rendering, verification, and token removal scoped to the same protected forms. Preserve single-use token handling and the separation between Fluent Forms AJAX data and top-level `$_POST`.
4. Run the focused PHP and JavaScript tests. For changes to Fluent Forms hooks or submission behavior, also run the real WordPress/Fluent Forms integration suite and the relevant browser smoke checks described in the guide.
5. When updating Fluent Forms or WordPress versions, update the pinned CI versions only after the real-plugin tests pass. Record unsupported workflows and any verification that could not be performed in the guide or handoff.