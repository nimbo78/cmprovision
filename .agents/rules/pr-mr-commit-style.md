# Zoomer Dual-Language Styleguide for PRs, MRs, Commits & Releases ✨💅🚀

This rule defines the official communication, pull request, merge request, commit, and release description standard for the repository.

---

## 🌟 Core Philosophy: "Vibe + Ironclad Evidence + Sequential Parity" 💅💯

Every PR, MR, release note, and document in this repo balances:
1. **Sequential Dual-Language Parity (Последовательный билингвал):** 
   - **Do NOT mix or interleave languages line-by-line within the same section.**
   - In PR descriptions and release notes, provide the **English section in full first**, followed by a divider (`---`), and then the **Russian section in full** (or separate dedicated localized files like `docs/` and `docs/ru/`).
   - PR / MR titles remain dual-language separated by `/`.
2. **Zoomer Vibe (Ультра-вайб без кринжа):** Friendly, high-energy presentation packed with expressive emojis and modern tech slang (*Glow Up, Drop, What's Cookin', no cap, на ультра-вайбе, без кринжа, лютый, завезли, фулл, фарш, годнота*).
3. **Hardcore Technical Rigor (Железные пруфы и точность):** Zero sacrifice of precision. Exact test counts (`2095 PHP tests, 69 JS tests`), real file paths with clickable links, hardware targets (`wlanpi-da2`, `d62`), exact failure modes, checksums, blast radiuses, and migration door types.

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

## 📝 PR & MR Body Structure (Sequential Format)

PR descriptions must be structured sequentially (English part fully, then Russian part fully; see `.github/pull_request_template.md`):

```markdown
# 🇬🇧 English

## 🌟 What's Cookin'? ✨💅
<High-level summary / elevator pitch explaining why this change was made.>

### 🎨 What Changed? 🖼️⚡
- **<Component / Feature 1>:** Detailed explanation with links to changed files.
- **<Component / Feature 2>:** Explanation of fixes, UI tweaks, or architecture updates.

### 🧪 Verification 💯
- [x] **Unit/Feature Tests:** `<count> PHP tests passed`.
- [x] **Frontend/JS Tests:** `<count> JavaScript tests passed`.
- [x] **Hardware / Docker:** Station tests (`wlanpi-da2`, `d62`) or Docker bench.
- [x] **Isolation:** Isolated worktree (`<worktree-name>`).

### ⚠️ Merge Danger & Blast Radius 🛡️
- **Door Type:** Two-way / One-way.
- **Blast Radius:** Configuration, database schema, queues, systemd services, or docs only.

---

# 🇷🇺 Русский

## 🌟 Чо за движ тут происходит? ✨💅
<Краткое саммари на русском с ключевой сутью изменений.>

### 🎨 Что завезли в этом дропе? 🖼️⚡
- **<Компонент / Фича 1>:** Подробное описание со ссылками на файлы.
- **<Компонент / Фича 2>:** Исправления, косметика, обновление диаграмм.

### 🧪 Тесты и валидация 💯
- [x] **PHP-тесты:** `<count> тестов пройдено`.
- [x] **JS-тесты:** `<count> тестов пройдено`.
- [x] **Стенд / Железо:** Проверено на стендах (`da2`, `d62`).
- [x] **Изоляция:** Ветка изолирована в worktree (`<worktree-name>`).

### ⚠️ Риски и влияние 🛡️
- **Тип двери:** Двусторонняя с бэкапом / Односторонняя.
- **Зона влияния:** Конфиги, база, очереди или только доки.
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

---

## 🚀 Release Notes Structure (Sequential Format)

Releases are published sequentially:
1. Complete English release notes (`# 🇬🇧 English`).
2. Divider `---`.
3. Complete Russian release notes (`# 🇷🇺 Русский`).
