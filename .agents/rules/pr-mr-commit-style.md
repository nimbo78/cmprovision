# Zoomer Dual-Language Styleguide for PRs, MRs, Commits & Releases ✨💅🚀

This rule defines the official communication, pull request, merge request, commit, and release description standard for the repository.

---

## 🌟 Core Philosophy: "Vibe + Ironclad Evidence" 💅💯

Every PR, MR, release note, and conversational update in this repo balances two pillars:
1. **Zoomer Dual-Language Vibe (Энергичный билингвал):** Friendly, high-energy, dual-language (EN/RU) presentation packed with expressive emojis and modern tech slang (*Glow Up, Drop, What's Cookin', no cap, на ультра-вайбе, без кринжа, лютый, завезли, фулл, фарш, годнота*).
2. **Hardcore Technical Rigor (Железные пруфы и точность):** Zero sacrifice of precision. Exact test counts (`2095 PHP tests, 69 JS tests`), real file paths with clickable links, hardware targets (`wlanpi-da2`, `d62`), exact failure modes, checksums, blast radiuses, and migration door types.

---

## 🏷️ PR & MR Titles

Format:
```text
<Emoji> <English Catchy Hook> <Emojis> / <Russian Catchy Hook> <Emojis>
```

### Examples:
- `📚 Dual-Language Glow Up: Bilingual English & Russian Docs Drop ✨🔥 / Завезли лютый билингвал: фулл дока на инглише и русском на ультра-вайбе 💅🌐`
- `📊 Archify Visual Drop: Interactive Provisioning Workflow & Stand Architecture ✨🚀 / Интерактивные схемы Archify: пайплайн провижининга и архитектура стенда 💅🖼️`
- `💅 Завезли лютый LikeC4 графон: архитектура на ультра-вайбе без кринжа 🚀🔥`
- `🚀 Selective Config Transfer & Wizard Glow-Up ✨🧙‍♂️ / Селективный перенос конфигов и лютые визарды 📦⚡`

---

## 📝 PR & MR Body Structure

Every PR body must follow this structure (see `.github/pull_request_template.md`):

```markdown
## 🌟 What's Cookin'? / Чо за движ тут происходит? ✨💅

<High-level bilingual summary / elevator pitch explaining why this change was made and what vibe it brings.>
*(Краткое саммари на русском с ключевой сутью изменений).*

---

### 🎨 What Changed? / Что завезли в этом дропе? 🖼️⚡

- **<Component / Feature 1>:** Detailed explanation with links to changed files.
- **<Component / Feature 2>:** Explanation of fixes, UI tweaks, or architecture updates.
- **<Docs & Extras>:** Documentation updates, schema validations, assets.

---

### 🧪 Verification / Тесты и валидация 💯

- [x] **Unit/Feature Tests:** `<count> PHP tests passed` (PHPUnit in-memory SQLite / Docker).
- [x] **Frontend/JS Tests:** `<count> JavaScript tests passed` (`npm run test:js`).
- [x] **Station / Hardware Verification:** Real station tests (`wlanpi-da2`, `d62`) or Docker bench.
- [x] **Isolation:** Isolated worktree (`<worktree-name>`) to avoid interfering with concurrent agent sessions.

---

### ⚠️ Merge Danger & Blast Radius / Риски и влияние 🛡️

- **Door Type:** Two-way with data backup / One-way (migrations).
- **Blast Radius:** Configuration, database schema, queues, systemd services, or docs only.
- **Rollback Plan:** Steps to revert if something goes sideways.
```

---

## 📦 Commit Messages

Follow Conventional Commits with an imperative, concise description:

```text
<type>(<scope>): <short description in English>
```

### Allowed Types:
- `feat`: New feature or user-facing capability.
- `fix`: Bugfix or error resolution.
- `docs`: Documentation, guides, diagram models, or translations.
- `test`: Adding or refactoring automated tests.
- `refactor`: Code change that neither fixes a bug nor adds a feature.
- `perf`: Performance improvement.
- `chore`: Maintenance, CI, dependencies, build scripts.

### Examples:
- `docs(diagrams): add interactive Archify provisioning workflow diagram (EN and RU)`
- `docs(assets): add Russian-localized hero and stand network diagram`
- `fix(settings): fix literal Blade directives in Settings service summary`
- `feat(transfer): add selective configuration transfer and creation wizards`

---

## 🚀 Release Notes Structure

Releases celebrate major milestones with maximum visual appeal and complete technical fidelity:

```markdown
## 🌟 What's Cookin'? / Главный замес релиза ✨💅

<Exciting bilingual opening paragraph highlighting the milestone.>

---

### 🎁 What's in the Box? / Что завезли в этом дропе? 📦⚡

#### 1. 🔀 <Major Feature 1>
- Bullet points explaining the capability and security/UX aspects.

#### 2. 🧙‍♂️ <Major Feature 2>
- Details on wizards, workflows, or architectural improvements.

#### 3. 💾 <Polish & Fixes>
- Specific bugs fixed, performance wins, UI polish.

---

### 🧪 Quality Assurance & Evidence / Пруфы и тесты 💯

- **PHP Test Suite:** `<count> tests passed`.
- **JavaScript Test Suite:** `<count> tests passed`.
- **Live Station Verification:** Results on testbeds (`da2`, `d62`).
- **Accessibility & Themes:** Mobile, dark/light themes, motion.

---

### 🚀 How to Upgrade / Как накатить обновление 🛠️

> [!IMPORTANT]
> Backup warning with `cmprovision-backup` or `Settings → Status → Backup`.

1. Download `<package>.deb` and `SHA256SUMS`.
2. Check sums: `sha256sum -c SHA256SUMS`.
3. Install: `sudo dpkg -i <package>.deb`.

---

*Enjoy the vibe and happy provisioning! / Всем кайфового провижининга без факапов!* 🍓🎉✨
```
