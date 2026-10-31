# eventon_archive compatibility status

Reviewed 2026-10-31; plugin **1.10.1** (working-tree fix based on HEAD `310abfb091f24fa372bec6d5bf206a7e4e3b5fd7`).

| Target | Source assessment |
| --- | --- |
| ARMember premium 8.0 + WordPress 7.1.4 | Source-compatible; helper delegation fixed in 1.10.1 |
| ARMember Lite 6.2 + WordPress 7.1.4 | Source-compatible; helper delegation fixed in 1.10.1 |

Post rules and `get_arm_term_meta` remain callable in both sources. Version 1.10.1 fixes delegation to `droc_noindex_is_arm_restricted`, the helper exported by DROC Noindex Restricted. The standalone post/term fallback remains in place. The term-meta reader also exists in Lite 6.2.

Validation: 72 tests / 94 assertions passed; EventON plus ARMember runtime not exercised. PHP syntax checks passed under PHP 8.5.12. **No runtime test on WordPress 7.1.4 was performed.** Existing `Tested up to` headers remain unchanged.

Source archives: premium `armember_8.0.zip`; Lite `armember-membership_6.2.zip`. The originally supplied `armemberlite_6.2.zip` contains premium 8.0 and was not used as Lite evidence.
