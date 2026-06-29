Verify each finding against the current code and only fix it if needed.



Inline comments:

In @.planning/milestones/v1.0-MILESTONE-AUDIT.md:

\- Around line 44-48: Update the summary table row for "02 Migration Extraction"

so its Tech Debt cell reflects the informational item recorded elsewhere (the

frontmatter and the "Tech Debt" detailed section) instead of "None"; locate the

table entry with the header row including "01 Views Extraction" / "02 Migration

Extraction" and change the Tech Debt column for "02 Migration Extraction" to

match the described informational item used in the Tech Debt section.



In @.planning/milestones/v1.0-phases/02-migration-extraction/02-01-PLAN.md:

\- Around line 38-41: The execution\_context block contains author-local absolute

paths ("@C:/Users/User/.claude/...") which are not portable; update the

execution\_context entries in

.planning/milestones/v1.0-phases/02-migration-extraction/02-01-PLAN.md to use

repo-relative or tool-stable identifiers (for example replace

"@C:/Users/User/.claude/get-shit-done/workflows/execute-plan.md" and the

template path with "@.claude/get-shit-done/workflows/execute-plan.md" or a

relative path like "@/workflows/execute-plan.md" or the canonical template

name), ensuring the symbols execution\_context and the two referenced entries are

updated so CI/reviewers can resolve them on any machine.

\- Around line 185-186: The summary line points to the wrong artifact location:

it references the top-level "phases" folder instead of the milestone tree;

update the reference for 02-01-SUMMARY.md so it lives under the milestone tree

(v1.0-phases/02-migration-extraction) rather than the incorrect phases path, and

apply the same fix for the similar incorrect reference to 03-01-PLAN.md in the

03-orchestrator-cleanup phase. Ensure the updated filename strings in the file

match the milestone-tree location so downstream audits find the artifacts.



In @.planning/milestones/v1.0-REQUIREMENTS.md:

\- Around line 62-82: The traceability table incorrectly marks CLEN-02 as

"Complete" and reports 10/10 mapped and 0 unmapped despite CLEN-02 not meeting

its target (actual: 5,390 lines > 500); update the Traceability section so the

row for CLEN-02 reflects its true status (e.g., "Incomplete" or "In Progress"),

adjust the summary counts under "Coverage" (reduce mapped count and increment

unmapped/partial as appropriate), and add a short note below the table

referencing CLEN-02 with the actual metric (5,390 lines) to make the dashboard

transparent.

\- Around line 19-31: The requirements doc

(.planning/milestones/v1.0-REQUIREMENTS.md) does not match the PR: the PR

extracts service classes (Period\_Service, Shift\_Service, Session\_Service,

Early\_Leave\_Service) from AttendanceModule.php but the doc only mentions

Frontend/Widget\_Shortcode.php, Frontend/Kiosk\_Shortcode.php and Migration.php;

either replace this archived requirements file with the correct artifact for

this PR or edit it to add/update the scope to list the four new service classes

and the refactor of AttendanceModule.php (include short descriptions of each

service and the expected behavior such as "AttendanceModule delegates to

Period\_Service, Shift\_Service, Session\_Service, Early\_Leave\_Service"), and keep

or adjust the existing VIEW-/MIGR- entries if they belong to a different

PR/phase.

\- Line 35: The milestone entry for CLEN-02 is incorrect: it claims

"AttendanceModule.php reduced to under 500 lines" is complete while the PR shows

AttendanceModule.php went from 7,332 → 5,390 lines (still far above 500). Update

the .planning milestone to either uncheck CLEN-02 (mark it incomplete) or revise

the requirement text to reflect the new target (e.g., change threshold from 500

to 5,390 or a new realistic goal) and ensure the identifier "CLEN-02" and file

name "AttendanceModule.php" remain referenced so reviewers can trace the change.



In `@includes/Modules/Attendance/Frontend/Widget\_Shortcode.php`:

\- Around line 679-685: The localized prompt key mismatch prevents the

selfie-ready message from showing: update the code in the selfie flow (function

startSelfie) to read the existing i18n key

window.sfsAttI18n.ready\_capture\_submit instead of

window.sfsAttI18n.ready\_capture; search for other usages (notably the other

block around lines 1417-1436) and replace any remaining ready\_capture references

with ready\_capture\_submit so the correct localized string is used.

\- Around line 43-45: The widget generates a unique instance id ($inst and

$root\_id) but several DOM IDs and JS selectors remain fixed, causing duplicate

IDs and cross-binding when multiple shortcodes render; update all HTML IDs

referenced in the ranges (including the controls in lines ~43-45, ~143-221,

~771-787, ~951-966) to include the instance suffix (e.g., append $inst or use

$root\_id as a prefix), and change the client-side code to scope queries to the

instance root (use document.getElementById($root\_id) or root.querySelector(...)

instead of global selectors) and bind event handlers on those scoped elements so

each widget instance manages its own status/buttons/selfie flow.

\- Around line 19-31: The render() method currently ignores shortcode attributes

because it takes no parameters and calls shortcode\_atts with an empty array;

update Widget\_Shortcode::render to accept a parameter like $atts (e.g.,

render(array $atts = \[])), forward that $atts into shortcode\_atts to respect

immersive="0"/"1" values, and update the caller

AttendanceModule::shortcode\_widget (and any other places that invoke

Widget\_Shortcode::render) to pass the received $atts through when delegating;

ensure the immersive truthiness logic still reads from the resolved

$atts\['immersive'].



In `@includes/Modules/Attendance/Migration.php`:

\- Around line 468-512: The capability strings in register\_caps() use underscores

instead of the required dotted format; update the capability arrays ($caps\_self,

$caps\_kiosk, $caps\_manage, $caps\_devices) to use dotted names (e.g.,

sfs\_hr.attendance.clock\_self, sfs\_hr.attendance.view\_self,

sfs\_hr.attendance.view\_team, sfs\_hr.attendance.edit\_team,

sfs\_hr.attendance.admin, sfs\_hr.attendance.clock\_kiosk,

sfs\_hr.attendance.edit\_devices) and replace any places that add or check those

caps (the foreach loops adding caps to get\_role('sfs\_hr\_employee'),

get\_role('sfs\_hr\_manager'), wp\_roles()->roles iteration,

get\_role('administrator'), and the device/admin cap block) to use the new dotted

strings; also scan the Attendance module for any other has\_cap/add\_cap calls

referencing the old underscore names and update them to the dotted equivalents.



---



Nitpick comments:

In @.planning/milestones/v1.0-phases/03-orchestrator-cleanup/03-01-PLAN.md:

\- Around line 124-125: The automated verification command hard-codes a local

absolute path ("C:/Users/User/repos/Simple-HR-Suite") making the check

non-portable; update the <verify> automated command to use a repo-relative root

(for example use a git-derived root like cd "$(git rev-parse --show-toplevel)"

or simply cd "." so it runs from the repo root) and keep the rest of the

pipeline (wc -l, grep checks for the AttendanceModule symbols) unchanged; also

apply the same replacement for the other occurrence noted (the similar commands

around lines referenced as 157-158).



In @.planning/milestones/v1.0-REQUIREMENTS.md:

\- Line 10: The document title header "# Requirements: Attendance Module Refactor

— Phase 2" is inconsistent with the body which spans Phases 1–3; update the

header to reflect full scope (for example "v1.0 Attendance Module Refactor (All

Phases)" or "Requirements: Attendance Module Refactor — Phases 1–3") or add a

clarifying subtitle, ensuring the header text and the traceability

table/requirements sections reference the same phase scope.



In `@includes/Modules/Attendance/Migration.php`:

\- Around line 498-512: The device/admin capability block has inconsistent

indentation; locate the block that defines $caps\_devices and the loops using

get\_role('administrator') and get\_role('sfs\_hr\_manager') and normalize spacing

to match the surrounding code style (align opening/closing braces and indent

foreach bodies and array\_merge parameters consistently), ensuring calls to

add\_cap($c) and array\_merge(...) are indented the same as adjacent blocks.

\- Around line 224-240: The add\_column\_if\_missing calls that build the device

table columns (the block setting $t = "{$p}sfs\_hr\_attendance\_devices"; and

subsequent add\_column\_if\_missing(...) calls) are not indented to match the

surrounding method body; reformat those lines to use the same 8-space

indentation as the rest of the method so the $t assignment and each

add\_column\_if\_missing invocation line up with other statements in the function

(preserving the existing code and argument order, only adjusting leading

whitespace).

\- Around line 485-491: The foreach in Migration.php iterates as "foreach (

wp\_roles()->roles as $role\_key => $def )" but $def is unused; update the loop to

avoid the unused-variable warning by renaming the second variable to a

deliberately unused name (e.g., "$\_def" or "$\_") or by iterating keys only

(e.g., "foreach ( array\_keys( wp\_roles()->roles ) as $role\_key )"), then keep

the existing logic that retrieves the role with get\_role($role\_key) and checks

$r->has\_cap('sfs\_hr.manage') before calling $r->add\_cap(...) to add caps.

\- Around line 324-328: The register\_caps() call currently runs on every

admin\_init via maybe\_install(); guard it with a persistent check (either a

dedicated option flag or stored module version) so it only runs once or on

version change: in maybe\_install() check an option like sfs\_hr\_caps\_registered

or compare a stored sfs\_hr\_att\_version to the current migration version before

calling register\_caps(), and after successfully calling register\_caps()

set/update that option (analogous to how migrate\_add\_foreign\_keys() uses

sfs\_hr\_att\_fk\_migrated); leave maybe\_seed\_defaults() and maybe\_seed\_kiosks()

behavior unchanged.







Verify each finding against the current code and only fix it if needed.



Inline comments:

In `@includes/Modules/Attendance/AttendanceModule.php`:

\- Around line 5206-5249: The function selfie\_mode\_for reads nested keys from

get\_option(self::OPT\_SETTINGS) assuming selfie\_policy, by\_dept\_id and

by\_employee are arrays; harden it by validating/casting before use: fetch $opt,

then ensure $policy = is\_array($opt\['selfie\_policy'] ?? null) ?

$opt\['selfie\_policy'] : \[] and similarly set $dept\_modes =

is\_array($policy\['by\_dept\_id'] ?? null) ? $policy\['by\_dept\_id'] : \[] and

$emp\_modes = is\_array($policy\['by\_employee'] ?? null) ? $policy\['by\_employee'] :

\[]; update the variables used in selfie\_mode\_for so indexing them cannot trigger

PHP 8 errors.



In `@includes/Modules/Attendance/Services/Session\_Service.php`:

\- Line 871: The function evaluate\_segments currently accepts an unused

$dayEndUtcTs parameter; either remove it from the signature and all call sites

or implement day-end capping: inside evaluate\_segments (when iterating interval

start/end timestamps in the interval-processing block) if $dayEndUtcTs > 0 cap

each interval's end timestamp to min($endUtcTs, $dayEndUtcTs), skip or truncate

intervals where start >= cap, and recalc any duration/late/early calculations

accordingly so no work beyond $dayEndUtcTs is counted; update callers (where

$dayCapUtcTs is passed) if you remove the parameter.



In `@includes/Modules/Attendance/Services/Shift\_Service.php`:

\- Around line 550-581: Add a thin static delegation wrapper in AttendanceModule

for build\_segments\_for\_date\_from\_dept so existing callers keep working:

implement AttendanceModule::build\_segments\_for\_date\_from\_dept that simply

returns

Services\\Shift\_Service::build\_segments\_for\_date\_from\_dept($dept\_id\_or\_slug,

$ymd), following the same pattern used by build\_segments\_from\_shift to forward

arguments and return the result.



---



Nitpick comments:

In `@includes/Modules/Attendance/AttendanceModule.php`:

\- Around line 5196-5199: Remove the dead private compatibility wrapper method

evaluate\_segments in AttendanceModule by deleting the private static function

evaluate\_segments(...) definition; this logic already delegates to

Services\\Session\_Service::evaluate\_segments and the private method is unused, so

remove it, run tests and grep for any remaining references to evaluate\_segments

in this file to ensure nothing calls it, and rely on

Session\_Service::evaluate\_segments as the single entry point.



In `@includes/Modules/Attendance/Services/Early\_Leave\_Service.php`:

\- Around line 133-144: The backfill loop in Early\_Leave\_Service.php builds

request\_number using COUNT(\*) which can collide with the existing

generate\_early\_leave\_request\_number/Helpers::generate\_reference\_number logic

(which uses MAX) and can reuse sequences when rows are deleted or run

concurrently; change the backfill to compute the next sequence the same way as

the helper (use MAX on request\_number for pattern 'EL-{year}-%' or simply call

Helpers::generate\_reference\_number / generate\_early\_leave\_request\_number for

each $row) and ensure atomicity (e.g., perform the update inside the same

transaction or use row/table locking) so concurrent backfills don't produce

duplicate request\_number values.

\- Around line 130-132: The query building for $missing uses raw table

interpolation; escape the table identifier with $wpdb->esc\_sql($table) (e.g.,

$safe\_table) and then use $wpdb->prepare() for the SQL string (use a placeholder

for the empty string comparison on request\_number) when calling

$wpdb->get\_results; update the call referencing

$table/$missing/$wpdb->get\_results to use the escaped table name and a prepared

statement like "SELECT id, created\_at FROM `{$safe\_table}` WHERE request\_number

IS NULL OR request\_number = %s ORDER BY id ASC" with '' as the bound value.



In `@includes/Modules/Attendance/Services/Session\_Service.php`:

\- Around line 267-268: The variable $is\_total\_hours in Session\_Service:: (within

includes/Modules/Attendance/Services/Session\_Service.php) is computed twice

using Policy\_Service::is\_total\_hours\_mode($employee\_id, $shift); remove the

redundant reassignment (the second call around the later block) and keep the

initial early determination so subsequent logic uses the single $is\_total\_hours

value; ensure no remaining references rely on the removed duplicate call.

\- Around line 988-989: Replace the raw get\_col call that populates $all\_active

with a prepared query: use $wpdb->prepare() when calling $wpdb->get\_col("SELECT

id FROM {$eT} WHERE status = %s", 'active') (keep $eT as the table variable),

then cast the result to array and apply array\_map('intval', ...) as before so

$all\_active remains an array of ints; update the code around the $all\_active

assignment in Session\_Service.php (the $wpdb->get\_col call) accordingly.

\- Around line 290-294: The variable $shift\_break\_start in Session\_Service.php is

assigned but never used; either remove the assignment to eliminate the unused

variable or use it where intended (e.g., in any break-window calculations or

validation). Locate the block setting $shift\_break\_minutes, $shift\_break\_policy,

$shift\_break\_start, $has\_mandatory\_break, and $shift\_no\_break and either delete

the $shift\_break\_start line or wire $shift\_break\_start into the logic that

determines break windows/conditions (methods or code paths that reference

$has\_mandatory\_break or $shift\_no\_break) so the value is actually consumed.

\- Around line 622-627: The hook call to do\_action('sfs\_hr\_attendance\_late', ...)

is missing richer timestamp data; update the payload to include expected\_time

and actual\_time (using the existing variables that hold the scheduled and

recorded times, e.g. $expected\_time and $actual\_time or compute them from the

session/attendance record) alongside 'minutes\_late', 'work\_date' ($ymd) and

'type' so listeners and templates can access both expected and actual arrival

times.



In `@includes/Modules/Attendance/Services/Shift\_Service.php`:

\- Around line 26-31: The method resolve\_shift\_for\_date declares an unused

$settings parameter; either remove the parameter if safe or suppress the static

analysis warning—if keeping it for API compatibility (since

AttendanceModule::resolve\_shift\_for\_date delegates to this method) add a PHPMD

suppression docblock (e.g. `@SuppressWarnings`(PHPMD.UnusedFormalParameter)) above

resolve\_shift\_for\_date, otherwise remove $settings from the signature and update

any callers (including AttendanceModule::resolve\_shift\_for\_date) to match the

new signature.







Verify each finding against the current code and only fix it if needed.



In `@includes/Modules/Attendance/Services/Shift\_Service.php` around lines 550 -

581, Add a thin static delegation wrapper in AttendanceModule for

build\_segments\_for\_date\_from\_dept so existing callers keep working: implement

AttendanceModule::build\_segments\_for\_date\_from\_dept that simply returns

Services\\Shift\_Service::build\_segments\_for\_date\_from\_dept($dept\_id\_or\_slug,

$ymd), following the same pattern used by build\_segments\_from\_shift to forward

arguments and return the result.









finding by sonarcloud:

includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php

Show 771 more lines

&nbsp; 0%   { box-shadow: 0 0 0 0 rgba(34,197,94,0.85); }

&nbsp; 100% { box-shadow: 0 0 0 32px rgba(34,197,94,0); }

}

</style>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" defer></script>

Make sure not using resource integrity feature is safe here.



<?php

// Make sure WP exposes a nonce helper on the front-end

wp\_enqueue\_script('wp-api');

?>

<script>




includes/Modules/Attendance/Frontend/Widget\_Shortcode.php

Show 76 more lines

New code

&nbsp;       }

&nbsp;   }

&nbsp;   ob\_start(); ?>

&nbsp;   <!-- Always load Leaflet so the map can show the user's current location -->

&nbsp;   <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>

Make sure not using resource integrity feature is safe here.



&nbsp;   <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>

&nbsp;   <?php if ( $immersive ): ?>

&nbsp;     <script>

&nbsp;       document.documentElement.classList.add('sfs-att-immersive');

&nbsp;       document.body.classList.add('sfs-att-immersive');







includes/Modules/Attendance/Frontend/Widget\_Shortcode.php

Show 77 more lines

&nbsp;   }

&nbsp;   ob\_start(); ?>

&nbsp;   <!-- Always load Leaflet so the map can show the user's current location -->

&nbsp;   <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>

&nbsp;   <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>

Make sure not using resource integrity feature is safe here.



&nbsp;   <?php if ( $immersive ): ?>

&nbsp;     <script>

&nbsp;       document.documentElement.classList.add('sfs-att-immersive');

&nbsp;       document.body.classList.add('sfs-att-immersive');





Check warning on line 753 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZEN\&open=AZzUDdu-qn45uAgxHZEN\&pullRequest=144

&nbsp;Check warning on line 843 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZE5\&open=AZzUDdu-qn45uAgxHZE5\&pullRequest=144

&nbsp;Check warning on line 200 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Split this 148 characters long line (which is greater than 120 authorized).



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SdMVxjo9SItbCb\&open=AZzVN6SdMVxjo9SItbCb\&pullRequest=144

&nbsp;Check warning on line 56 in includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6QAMVxjo9SIta\_3\&open=AZzVN6QAMVxjo9SIta\_3\&pullRequest=144

&nbsp;Check warning on line 226 in includes/Modules/Attendance/Migration.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Put exactly one space after comma separated arguments.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6UTMVxjo9SItbI6\&open=AZzVN6UTMVxjo9SItbI6\&pullRequest=144

&nbsp;Check warning on line 724 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZD3\&open=AZzUDdu-qn45uAgxHZD3\&pullRequest=144

&nbsp;Check warning on line 168 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Split this 156 characters long line (which is greater than 120 authorized).



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SdMVxjo9SItbCX\&open=AZzVN6SdMVxjo9SItbCX\&pullRequest=144

&nbsp;Check warning on line 333 in includes/Modules/Attendance/Migration.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6UTMVxjo9SItbH\_\&open=AZzVN6UTMVxjo9SItbH\_\&pullRequest=144

&nbsp;Check warning on line 474 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbEx\&open=AZzVN6SeMVxjo9SItbEx\&pullRequest=144

&nbsp;Check warning on line 93 in includes/Modules/Attendance/Services/Early\_Leave\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDduTqn45uAgxHY77\&open=AZzUDduTqn45uAgxHY77\&pullRequest=144

&nbsp;Check warning on line 257 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbEO\&open=AZzVN6SeMVxjo9SItbEO\&pullRequest=144

&nbsp;Check warning on line 686 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbGB\&open=AZzVN6SeMVxjo9SItbGB\&pullRequest=144

&nbsp;Check warning on line 257 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY-N\&open=AZzUDdutqn45uAgxHY-N\&pullRequest=144

&nbsp;Check warning on line 393 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY-r\&open=AZzUDdutqn45uAgxHY-r\&pullRequest=144

&nbsp;Check warning on line 32 in includes/Modules/Attendance/Services/Period\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDducqn45uAgxHY8U\&open=AZzUDducqn45uAgxHY8U\&pullRequest=144

&nbsp;Check warning on line 4 in includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6QAMVxjo9SIta\_t\&open=AZzVN6QAMVxjo9SIta\_t\&pullRequest=144

&nbsp;Check warning on line 65 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbDN\&open=AZzVN6SeMVxjo9SItbDN\&pullRequest=144

&nbsp;Check warning on line 696 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbGQ\&open=AZzVN6SeMVxjo9SItbGQ\&pullRequest=144

&nbsp;Check warning on line 848 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZE8\&open=AZzUDdu-qn45uAgxHZE8\&pullRequest=144

&nbsp;Check warning on line 28 in includes/Modules/Attendance/Services/Period\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDducqn45uAgxHY8S\&open=AZzUDducqn45uAgxHY8S\&pullRequest=144

&nbsp;Check warning on line 88 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY9m\&open=AZzUDdutqn45uAgxHY9m\&pullRequest=144

&nbsp;Check warning on line 139 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Put only one space between the closing parenthesis and the opening curly brace.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY9w\&open=AZzUDdutqn45uAgxHY9w\&pullRequest=144

&nbsp;Check warning on line 539 in includes/Modules/Attendance/Migration.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6UTMVxjo9SItbIv\&open=AZzVN6UTMVxjo9SItbIv\&pullRequest=144

&nbsp;Check warning on line 244 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY-E\&open=AZzUDdutqn45uAgxHY-E\&pullRequest=144

&nbsp;Check warning on line 135 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Put only one space between the closing parenthesis and the opening curly brace.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY9u\&open=AZzUDdutqn45uAgxHY9u\&pullRequest=144

&nbsp;Check warning on line 731 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZD9\&open=AZzUDdu-qn45uAgxHZD9\&pullRequest=144

&nbsp;Check warning on line 514 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY\_Q\&open=AZzUDdutqn45uAgxHY\_Q\&pullRequest=144

&nbsp;Check warning on line 814 in includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6QBMVxjo9SItbBc\&open=AZzVN6QBMVxjo9SItbBc\&pullRequest=144

&nbsp;Check warning on line 823 in includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6QBMVxjo9SItbBu\&open=AZzVN6QBMVxjo9SItbBu\&pullRequest=144

&nbsp;Check warning on line 248 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY-G\&open=AZzUDdutqn45uAgxHY-G\&pullRequest=144

&nbsp;Check warning on line 144 in includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Split this 124 characters long line (which is greater than 120 authorized).



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6QAMVxjo9SIta-z\&open=AZzVN6QAMVxjo9SIta-z\&pullRequest=144

&nbsp;Check warning on line 447 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbEt\&open=AZzVN6SeMVxjo9SItbEt\&pullRequest=144

&nbsp;Check warning on line 437 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY-6\&open=AZzUDdutqn45uAgxHY-6\&pullRequest=144

&nbsp;Check warning on line 28 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZAf\&open=AZzUDdu-qn45uAgxHZAf\&pullRequest=144

&nbsp;Check warning on line 797 in includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6QBMVxjo9SItbA7\&open=AZzVN6QBMVxjo9SItbA7\&pullRequest=144

&nbsp;Check warning on line 755 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZEP\&open=AZzUDdu-qn45uAgxHZEP\&pullRequest=144

&nbsp;Check warning on line 113 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZA4\&open=AZzUDdu-qn45uAgxHZA4\&pullRequest=144

&nbsp;Check warning on line 176 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbED\&open=AZzVN6SeMVxjo9SItbED\&pullRequest=144

&nbsp;Check warning on line 671 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbFk\&open=AZzVN6SeMVxjo9SItbFk\&pullRequest=144

&nbsp;Check warning on line 983 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Either split this list into multiple lines, aligned at column "12" or put all arguments on line "982".



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZFa\&open=AZzUDdu-qn45uAgxHZFa\&pullRequest=144

&nbsp;Check warning on line 668 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbFf\&open=AZzVN6SeMVxjo9SItbFf\&pullRequest=144

&nbsp;Check warning on line 28 in includes/Modules/Attendance/Services/Period\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDducqn45uAgxHY8R\&open=AZzUDducqn45uAgxHY8R\&pullRequest=144

&nbsp;Check warning on line 321 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZB7\&open=AZzUDdu-qn45uAgxHZB7\&pullRequest=144

&nbsp;Check warning on line 507 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

2 statements were found on this line. Reformat the code to have only one statement per line.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZFq\&open=AZzUDdu-qn45uAgxHZFq\&pullRequest=144

&nbsp;Check warning on line 810 in includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Split this 128 characters long line (which is greater than 120 authorized).



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6QAMVxjo9SIta\_M\&open=AZzVN6QAMVxjo9SIta\_M\&pullRequest=144

&nbsp;Check warning on line 569 in includes/Modules/Attendance/Services/Shift\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

2 statements were found on this line. Reformat the code to have only one statement per line.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdutqn45uAgxHY\_6\&open=AZzUDdutqn45uAgxHY\_6\&pullRequest=144

&nbsp;Check warning on line 301 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZB2\&open=AZzUDdu-qn45uAgxHZB2\&pullRequest=144

&nbsp;Check warning on line 148 in includes/Modules/Attendance/Frontend/Kiosk\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Split this 126 characters long line (which is greater than 120 authorized).



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6QAMVxjo9SIta-0\&open=AZzVN6QAMVxjo9SIta-0\&pullRequest=144

&nbsp;Check warning on line 732 in includes/Modules/Attendance/Services/Session\_Service.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Remove all space after the opening parenthesis and before the closing parenthesis.



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzUDdu-qn45uAgxHZD-\&open=AZzUDdu-qn45uAgxHZD-\&pullRequest=144

&nbsp;Check warning on line 1720 in includes/Modules/Attendance/Frontend/Widget\_Shortcode.php 



@sonarqubecloud

sonarqubecloud

/ SonarCloud Code Analysis

Split this 125 characters long line (which is greater than 120 authorized).



See more on https://sonarcloud.io/project/issues?id=onabhani\_Simple-HR-Suite\&issues=AZzVN6SeMVxjo9SItbC2\&open=AZzVN6SeMVxjo9SItbC2\&pullRequest=144





