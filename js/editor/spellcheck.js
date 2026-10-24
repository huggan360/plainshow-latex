/*
 * Spelling in .tex files, English or Swedish (Hunspell dictionaries through
 * typo.js). Commands, comments and maths are skipped. Right-click a marked
 * word for suggestions or to add it to your own dictionary.
 */
(function (Lx) {
    'use strict';

    const SPELLCHECK_LANGUAGE_STORAGE_KEY = 'latexSpellcheckLanguage';
    const SPELLCHECK_CUSTOM_WORDS_STORAGE_KEY = 'latexSpellcheckCustomWords';
    const SPELLCHECK_SUPPORTED_LANGUAGES = {
      en_US: {
        locale: 'en-US',
        aff: 'assets/dictionaries/en_US.aff',
        dic: 'assets/dictionaries/en_US.dic'
      },
      sv_SE: {
        locale: 'sv-SE',
        aff: 'assets/dictionaries/sv_SE.aff',
        dic: 'assets/dictionaries/sv_SE.dic'
      }
    };
    const SPELLCHECK_ALLOWLIST = new Set([
      'BibTeX',
      'LaTeX',
      'LuaLaTeX',
      'MiKTeX',
      'Overleaf',
      'PDFLaTeX',
      'TikZ',
      'XeLaTeX',
      'biblatex',
      'pdflatex',
      'xelatex'
    ]);
    const spellcheckEditors = new Set();
    const spellcheckEditorState = new WeakMap();
    const spellcheckDictionaryPromises = {};
    const spellcheckDictionaries = {};
    const spellcheckWordCaches = {};
    const spellcheckSuggestionCaches = {};
    const spellcheckCustomDictionaries = {};
    let currentSpellcheckLanguage = localStorage.getItem(SPELLCHECK_LANGUAGE_STORAGE_KEY) || 'en_US';
    let spellcheckContextMenu = null;

    if (!SPELLCHECK_SUPPORTED_LANGUAGES[currentSpellcheckLanguage]) {
      currentSpellcheckLanguage = 'en_US';
    }

    const SPELLCHECK_NAME_COMMANDS = new Set([
      'documentclass', 'usepackage', 'RequirePackage', 'input', 'include', 'includeonly', 'subfile', 'includegraphics',
      'label', 'ref', 'eqref', 'pageref', 'autoref', 'cref', 'Cref', 'nameref', 'cite', 'citep', 'citet', 'nocite', 'parencite', 'textcite', 'autocite',
      'begin', 'end', 'url', 'href', 'bibliography', 'bibliographystyle', 'addbibresource', 'newcommand', 'renewcommand', 'newenvironment',
      'usetikzlibrary', 'graphicspath', 'setlength', 'setcounter', 'pagestyle', 'thispagestyle', 'hypersetup', 'geometry', 'color', 'textcolor'
    ]);

    const SPELLCHECK_SETUP_COMMANDS = new Set([
      'definecolor', 'colorlet', 'lstdefinestyle', 'lstset', 'lstinputlisting', 'hypersetup', 'geometry', 'newcommand', 'renewcommand',
      'providecommand', 'newenvironment', 'renewenvironment', 'DeclareMathOperator', 'tikzset', 'pgfplotsset', 'captionsetup', 'sisetup',
      'setmainfont', 'setsansfont', 'setmonofont', 'usetikzlibrary', 'setlength', 'setcounter', 'addtolength', 'newtheorem', 'theoremstyle',
      'usetheme', 'usecolortheme', 'pagenumbering', 'bibliographystyle', 'graphicspath', 'input', 'include', 'usepackage', 'documentclass'
    ]);

    /** Past a command's [optional] and {first} argument. */
    function skipSpellcheckArguments(text, index) {
      const skipGroup = (open, close) => {
        while (text[index] === ' ') index += 1;
        if (text[index] !== open) return false;
        let depth = 0;
        for (; index < text.length; index += 1) {
          if (text[index] === open) depth += 1;
          else if (text[index] === close && --depth === 0) { index += 1; return true; }
          else if (text[index] === '\n' && open === '[') return false;
        }
        return true;
      };
      while (skipGroup('[', ']')) { /* optional arguments */ }
      skipGroup('{', '}');
      return index;
    }

    function getSpellcheckState(cm) {
      let state = spellcheckEditorState.get(cm);
      if (!state) {
        state = {
          attached: false,
          misspellings: [],
          scheduleTimer: null,
          runId: 0
        };
        spellcheckEditorState.set(cm, state);
      }
      return state;
    }

    function normalizeSpellcheckWord(word) {
      return word.normalize('NFC');
    }

    function getSpellcheckLocale(language) {
      return SPELLCHECK_SUPPORTED_LANGUAGES[language].locale;
    }

    function normalizeSpellcheckDictionaryKey(word, language) {
      return normalizeSpellcheckWord(word).toLocaleLowerCase(getSpellcheckLocale(language));
    }

    function isSpellcheckLetter(char) {
      return /[\p{L}\p{M}]/u.test(char);
    }

    function isSpellcheckCommandLetter(char) {
      return /[A-Za-z@]/.test(char);
    }

    function isSpellcheckEscaped(text, index) {
      let slashCount = 0;
      for (let i = index - 1; i >= 0 && text[i] === '\\'; i -= 1) {
        slashCount += 1;
      }
      return slashCount % 2 === 1;
    }

    function isSpellcheckCandidate(word) {
      if (!word || SPELLCHECK_ALLOWLIST.has(word)) {
        return false;
      }
      if (word.length < 2 || /\d/.test(word)) {
        return false;
      }
      if (/^[A-ZÅÄÖ]{2,}$/u.test(word)) {
        return false;
      }
      return true;
    }

    function spellcheckWordVariants(word, locale) {
      const variants = [];
      const normalized = normalizeSpellcheckWord(word);
      const lower = normalized.toLocaleLowerCase(locale);
      const capitalized = lower.charAt(0).toLocaleUpperCase(locale) + lower.slice(1);

      [normalized, lower, capitalized].forEach((variant) => {
        if (variant && !variants.includes(variant)) {
          variants.push(variant);
        }
      });

      return variants;
    }

    function getCustomSpellcheckDictionary(language) {
      if (spellcheckCustomDictionaries[language]) {
        return spellcheckCustomDictionaries[language];
      }

      let persisted = {};
      try {
        persisted = JSON.parse(localStorage.getItem(SPELLCHECK_CUSTOM_WORDS_STORAGE_KEY) || '{}');
      } catch (error) {
        console.error('Could not read custom spell-check dictionary:', error);
      }

      const words = Array.isArray(persisted[language]) ? persisted[language] : [];
      const dictionary = new Set(
        words.map((word) => normalizeSpellcheckDictionaryKey(word, language))
      );
      spellcheckCustomDictionaries[language] = dictionary;
      return dictionary;
    }

    function persistCustomSpellcheckDictionaries() {
      const payload = {};
      Object.keys(SPELLCHECK_SUPPORTED_LANGUAGES).forEach((language) => {
        payload[language] = Array.from(getCustomSpellcheckDictionary(language)).sort();
      });
      localStorage.setItem(SPELLCHECK_CUSTOM_WORDS_STORAGE_KEY, JSON.stringify(payload));
    }

    function isCustomSpellcheckWord(language, word) {
      return getCustomSpellcheckDictionary(language).has(
        normalizeSpellcheckDictionaryKey(word, language)
      );
    }

    function clearSpellcheckCaches(language) {
      spellcheckWordCaches[language] = new Map();
      spellcheckSuggestionCaches[language] = new Map();
    }

    function addWordToSpellcheckDictionary(language, word) {
      const normalized = normalizeSpellcheckDictionaryKey(word, language);
      if (!normalized) {
        return;
      }

      const customDictionary = getCustomSpellcheckDictionary(language);
      if (customDictionary.has(normalized)) {
        return;
      }

      customDictionary.add(normalized);
      persistCustomSpellcheckDictionaries();
      clearSpellcheckCaches(language);
      spellcheckEditors.forEach((cm) => {
        scheduleSpellcheck(cm, 0);
      });
    }

    async function loadSpellcheckDictionary(language) {
      if (spellcheckDictionaries[language]) {
        return spellcheckDictionaries[language];
      }

      if (!spellcheckDictionaryPromises[language]) {
        const config = SPELLCHECK_SUPPORTED_LANGUAGES[language];
        spellcheckDictionaryPromises[language] = Promise.all([
          fetch(config.aff).then((response) => {
            if (!response.ok) {
              throw new Error(`Could not load ${config.aff}`);
            }
            return response.text();
          }),
          fetch(config.dic).then((response) => {
            if (!response.ok) {
              throw new Error(`Could not load ${config.dic}`);
            }
            return response.text();
          })
        ]).then(([affData, dicData]) => {
          const dictionary = new Typo(language, affData, dicData, { platform: 'any' });
          spellcheckDictionaries[language] = dictionary;
          spellcheckWordCaches[language] = new Map();
          spellcheckSuggestionCaches[language] = new Map();
          return dictionary;
        }).catch((error) => {
          delete spellcheckDictionaryPromises[language];
          throw error;
        });
      }

      return spellcheckDictionaryPromises[language];
    }

    function isWordCorrectlySpelled(dictionary, language, word) {
      const normalized = normalizeSpellcheckWord(word);
      const cache = spellcheckWordCaches[language] || new Map();

      if (!spellcheckWordCaches[language]) {
        spellcheckWordCaches[language] = cache;
      }
      if (cache.has(normalized)) {
        return cache.get(normalized);
      }

      if (isCustomSpellcheckWord(language, normalized)) {
        cache.set(normalized, true);
        return true;
      }

      const locale = getSpellcheckLocale(language);
      const isCorrect = spellcheckWordVariants(normalized, locale).some((variant) => dictionary.check(variant));
      cache.set(normalized, isCorrect);
      return isCorrect;
    }

    function collectMisspellings(cm, dictionary, language) {
      const misspellings = [];
      const text = cm.getValue();
      let index = 0;
      let inComment = false;
      let mathMode = null;

      while (index < text.length) {
        const char = text[index];
        const nextChar = text[index + 1] || '';

        if (char === '\r') {
          index += 1;
          continue;
        }

        if (char === '\n') {
          inComment = false;
          index += 1;
          continue;
        }

        if (inComment) {
          index += 1;
          continue;
        }

        if (mathMode === 'paren') {
          if (char === '\\' && nextChar === ')') {
            mathMode = null;
            index += 2;
            continue;
          }
          index += 1;
          continue;
        }

        if (mathMode === 'bracket') {
          if (char === '\\' && nextChar === ']') {
            mathMode = null;
            index += 2;
            continue;
          }
          index += 1;
          continue;
        }

        if (mathMode === 'inlineDollar') {
          if (char === '$' && !isSpellcheckEscaped(text, index) && nextChar !== '$') {
            mathMode = null;
          }
          index += 1;
          continue;
        }

        if (mathMode === 'displayDollar') {
          if (char === '$' && nextChar === '$' && !isSpellcheckEscaped(text, index)) {
            mathMode = null;
            index += 2;
            continue;
          }
          index += 1;
          continue;
        }

        if (char === '%' && !isSpellcheckEscaped(text, index)) {
          inComment = true;
          index += 1;
          continue;
        }

        if (char === '\\' && nextChar === '(') {
          mathMode = 'paren';
          index += 2;
          continue;
        }

        if (char === '\\' && nextChar === '[') {
          mathMode = 'bracket';
          index += 2;
          continue;
        }

        if (char === '$' && !isSpellcheckEscaped(text, index)) {
          mathMode = nextChar === '$' ? 'displayDollar' : 'inlineDollar';
          index += nextChar === '$' ? 2 : 1;
          continue;
        }

        if (char === '\\') {
          index += 1;
          const commandStart = index;
          while (index < text.length && isSpellcheckCommandLetter(text[index])) {
            index += 1;
          }
          // Arguments that are names, not prose: packages, files, labels, keys.
          const command = text.slice(commandStart, index);
          if (SPELLCHECK_SETUP_COMMANDS.has(command)) {
            // Every argument is settings: names, colours, keys.
            let next = skipSpellcheckArguments(text, index);
            while (next !== index) { index = next; next = skipSpellcheckArguments(text, index); }
          } else if (SPELLCHECK_NAME_COMMANDS.has(command)) {
            index = skipSpellcheckArguments(text, index);
          }
          continue;
        }

        if (!isSpellcheckLetter(char)) {
          index += 1;
          continue;
        }

        const wordStart = index;
        while (index < text.length && isSpellcheckLetter(text[index])) {
          index += 1;
        }

        const word = text.slice(wordStart, index);
        if (!isSpellcheckCandidate(word)) {
          continue;
        }

        if (!isWordCorrectlySpelled(dictionary, language, word)) {
          misspellings.push({
            word,
            from: cm.posFromIndex(wordStart),
            to: cm.posFromIndex(index)
          });
        }
      }

      return misspellings;
    }

    function clearSpellcheckMarks(cm) {
      const state = getSpellcheckState(cm);
      state.misspellings.forEach((entry) => {
        if (entry.marker) {
          entry.marker.clear();
        }
      });
      state.misspellings = [];
    }

    function scheduleSpellcheck(cm, delay = 300) {
      const state = getSpellcheckState(cm);
      clearTimeout(state.scheduleTimer);
      state.scheduleTimer = setTimeout(() => {
        runSpellcheck(cm);
      }, delay);
    }

    async function runSpellcheck(cm) {
      const state = getSpellcheckState(cm);
      state.runId += 1;
      const runId = state.runId;
      const language = currentSpellcheckLanguage;

      if (cm.getOption('mode') !== 'stex') {
        clearSpellcheckMarks(cm);
        return;
      }

      try {
        const dictionary = await loadSpellcheckDictionary(language);
        if (runId !== state.runId) {
          return;
        }

        clearSpellcheckMarks(cm);
        const misspellings = collectMisspellings(cm, dictionary, language);
        if (runId !== state.runId) {
          return;
        }

        state.misspellings = misspellings.map((entry) => ({
          ...entry,
          marker: cm.markText(entry.from, entry.to, { className: 'cm-spell-error' })
        }));
      } catch (error) {
        console.error('Spell-check setup failed:', error);
      }
    }

    function hideSpellcheckContextMenu() {
      if (!spellcheckContextMenu) {
        return;
      }
      spellcheckContextMenu.classList.remove('show');
      spellcheckContextMenu.style.display = 'none';
      spellcheckContextMenu.innerHTML = '';
    }

    function ensureSpellcheckContextMenu() {
      if (spellcheckContextMenu) {
        return spellcheckContextMenu;
      }

      spellcheckContextMenu = document.createElement('div');
      spellcheckContextMenu.id = 'spellcheckContextMenu';
      spellcheckContextMenu.className = 'spellcheck-context-menu';
      spellcheckContextMenu.style.display = 'none';
      spellcheckContextMenu.addEventListener('mousedown', (event) => {
        event.preventDefault();
      });
      spellcheckContextMenu.addEventListener('contextmenu', (event) => {
        event.preventDefault();
      });
      document.body.appendChild(spellcheckContextMenu);

      // Closes on a press anywhere else, Escape, scrolling or resizing. Not on the
      // editor losing focus: a right-click blurs CodeMirror for a moment, which
      // closed the menu right after it opened.
      const outside = (event) => {
        if (!spellcheckContextMenu.contains(event.target)) {
          hideSpellcheckContextMenu();
        }
      };
      document.addEventListener('mousedown', outside, true);
      document.addEventListener('scroll', outside, true);
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          hideSpellcheckContextMenu();
        }
      }, true);
      window.addEventListener('resize', hideSpellcheckContextMenu);

      return spellcheckContextMenu;
    }

    function getSpellcheckSuggestions(dictionary, language, word) {
      const normalized = normalizeSpellcheckWord(word);
      const cache = spellcheckSuggestionCaches[language] || new Map();

      if (!spellcheckSuggestionCaches[language]) {
        spellcheckSuggestionCaches[language] = cache;
      }
      if (cache.has(normalized)) {
        return cache.get(normalized);
      }

      const suggestionResult = dictionary.suggest(normalized, 8);
      const suggestions = (Array.isArray(suggestionResult) ? suggestionResult : [])
        .filter((suggestion, index, list) => suggestion && list.indexOf(suggestion) === index)
        .slice(0, 8);

      cache.set(normalized, suggestions);
      return suggestions;
    }

    function getSpellcheckWordRangeAtPosition(cm, pos) {
      const text = cm.getValue();
      const size = text.length;
      const exactIndex = Math.max(0, Math.min(size, cm.indexFromPos(pos)));
      const candidateIndexes = [exactIndex, exactIndex - 1, exactIndex + 1].filter((index, idx, list) => (
        index >= 0 && index <= size && list.indexOf(index) === idx
      ));

      for (const index of candidateIndexes) {
        if (!isSpellcheckLetter(text[index]) && !isSpellcheckLetter(text[index - 1])) {
          continue;
        }

        let start = index;
        let end = index;

        if (!isSpellcheckLetter(text[start]) && isSpellcheckLetter(text[start - 1])) {
          start -= 1;
          end = start;
        }

        while (start > 0 && isSpellcheckLetter(text[start - 1])) {
          start -= 1;
        }
        while (end < size && isSpellcheckLetter(text[end])) {
          end += 1;
        }

        if (end > start) {
          return {
            word: text.slice(start, end),
            from: cm.posFromIndex(start),
            to: cm.posFromIndex(end)
          };
        }
      }

      return null;
    }

    function getMisspellingAtPosition(cm, pos) {
      const state = getSpellcheckState(cm);
      const wordRange = getSpellcheckWordRangeAtPosition(cm, pos);
      if (!wordRange) {
        return null;
      }

      return state.misspellings.find((entry) => {
        const range = entry.marker && entry.marker.find();
        return range
          && CodeMirror.cmpPos(wordRange.from, range.from) === 0
          && CodeMirror.cmpPos(wordRange.to, range.to) === 0;
      }) || null;
    }

    async function resolveMisspellingAtPosition(cm, pos) {
      const existingMisspelling = getMisspellingAtPosition(cm, pos);
      if (existingMisspelling) {
        return existingMisspelling;
      }

      const wordRange = getSpellcheckWordRangeAtPosition(cm, pos);
      if (!wordRange || !isSpellcheckCandidate(wordRange.word)) {
        return null;
      }

      const language = currentSpellcheckLanguage;
      const dictionary = await loadSpellcheckDictionary(language);
      if (isWordCorrectlySpelled(dictionary, language, wordRange.word)) {
        return null;
      }

      return {
        word: wordRange.word,
        from: wordRange.from,
        to: wordRange.to,
        marker: null
      };
    }

    async function showSpellcheckContextMenu(cm, misspelling, clientX, clientY) {
      try {
        const language = currentSpellcheckLanguage;
        const dictionary = await loadSpellcheckDictionary(language);
        const menu = ensureSpellcheckContextMenu();
        const suggestions = getSpellcheckSuggestions(dictionary, language, misspelling.word);
        const title = document.createElement('div');
        title.className = 'spellcheck-context-title';
        title.textContent = psT('Spelling');

        const currentWord = document.createElement('div');
        currentWord.className = 'spellcheck-context-word';
        currentWord.textContent = misspelling.word;

        menu.replaceChildren(title, currentWord);

        if (suggestions.length === 0) {
          const empty = document.createElement('div');
          empty.className = 'spellcheck-context-empty';
          empty.textContent = psT('No suggestions');
          menu.appendChild(empty);
        } else {
          suggestions.forEach((suggestion) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dropdown-item';
            button.textContent = suggestion;
            button.addEventListener('click', (event) => {
              event.preventDefault();
              event.stopPropagation();
              const range = misspelling.marker && misspelling.marker.find()
                ? misspelling.marker.find()
                : { from: misspelling.from, to: misspelling.to };
              cm.focus();
              cm.replaceRange(suggestion, range.from, range.to, '+spellcheck');
              hideSpellcheckContextMenu();
            });
            menu.appendChild(button);
          });
        }

        const divider = document.createElement('div');
        divider.className = 'dropdown-divider';
        menu.appendChild(divider);

        const addButton = document.createElement('button');
        addButton.type = 'button';
        addButton.className = 'dropdown-item';
        addButton.textContent = psT('Add "{word}" to dictionary', { word: misspelling.word });
        addButton.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          addWordToSpellcheckDictionary(language, misspelling.word);
          hideSpellcheckContextMenu();
          Lx.toast(psT('Added "{word}" to {value} dictionary.', { word: misspelling.word, value: language === 'sv_SE' ? 'Svenska' : 'English' }), 'success');
        });
        menu.appendChild(addButton);

        menu.style.display = 'block';
        menu.classList.add('show');
        menu.style.left = '0px';
        menu.style.top = '0px';

        const menuWidth = menu.offsetWidth;
        const menuHeight = menu.offsetHeight;
        const safeLeft = Math.min(clientX, window.innerWidth - menuWidth - 12);
        const safeTop = Math.min(clientY, window.innerHeight - menuHeight - 12);
        menu.style.left = `${Math.max(12, safeLeft)}px`;
        menu.style.top = `${Math.max(12, safeTop)}px`;
      } catch (error) {
        console.error('Could not open spell-check suggestions:', error);
      }
    }

    async function handleSpellcheckContextMenu(cm, event) {
      if (cm.getOption('mode') !== 'stex') {
        hideSpellcheckContextMenu();
        return;
      }

      const target = event.target && event.target.nodeType === Node.ELEMENT_NODE
        ? event.target
        : event.target && event.target.parentElement
          ? event.target.parentElement
          : null;

      if (!target || !cm.getWrapperElement().contains(target)) {
        hideSpellcheckContextMenu();
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      const pos = cm.coordsChar({ left: event.clientX, top: event.clientY - 6 }, 'window');
      const misspelling = await resolveMisspellingAtPosition(cm, pos);
      if (!misspelling) {
        hideSpellcheckContextMenu();
        return;
      }

      showSpellcheckContextMenu(cm, misspelling, event.clientX, event.clientY);
    }

    function syncSpellcheckLanguageSelectors(isLoading = false) {
      document.querySelectorAll('.spellcheck-language-select').forEach((select) => {
        select.value = currentSpellcheckLanguage;
        select.disabled = isLoading;
      });
    }

    async function changeSpellcheckLanguage(language) {
      if (!SPELLCHECK_SUPPORTED_LANGUAGES[language]) {
        return;
      }

      currentSpellcheckLanguage = language;
      localStorage.setItem(SPELLCHECK_LANGUAGE_STORAGE_KEY, currentSpellcheckLanguage);
      syncSpellcheckLanguageSelectors(true);

      try {
        await loadSpellcheckDictionary(currentSpellcheckLanguage);
        spellcheckEditors.forEach((cm) => {
          scheduleSpellcheck(cm, 0);
        });
      } catch (error) {
        console.error('Spell-check language switch failed:', error);
        Lx.toast(psT('Spell-check dictionary could not be loaded.'), 'danger');
      } finally {
        syncSpellcheckLanguageSelectors(false);
      }
    }

    function registerSpellcheckControls() {
      syncSpellcheckLanguageSelectors(true);
      document.querySelectorAll('.spellcheck-language-select').forEach((select) => {
        if (select.dataset.spellcheckBound === 'true') {
          return;
        }
        select.dataset.spellcheckBound = 'true';
        select.addEventListener('change', (event) => {
          changeSpellcheckLanguage(event.target.value);
        });
      });

      loadSpellcheckDictionary(currentSpellcheckLanguage)
        .then(() => {
          syncSpellcheckLanguageSelectors(false);
          spellcheckEditors.forEach((cm) => {
            scheduleSpellcheck(cm, 0);
          });
        })
        .catch((error) => {
          console.error('Spell-check initialization failed:', error);
          Lx.toast(psT('Spell-check dictionaries could not be loaded.'), 'danger');
          syncSpellcheckLanguageSelectors(false);
        });
    }

    function attachSpellcheckEditor(cm) {
      const state = getSpellcheckState(cm);
      if (state.attached) {
        scheduleSpellcheck(cm, 0);
        return;
      }

      state.attached = true;
      spellcheckEditors.add(cm);
      cm.on('change', () => {
        scheduleSpellcheck(cm);
      });
      // Through CodeMirror, so preventDefault also stops its own right-click handling,
      // which moves focus around and scrolls, and closed the menu again.
      cm.on('contextmenu', (editor, event) => {
        handleSpellcheckContextMenu(cm, event);
      });
      scheduleSpellcheck(cm, 0);
    }

    Lx.on('editor', function (editor) {
        attachSpellcheckEditor(editor);
        // The marks belong to the file being left; clear them and check the new one.
        editor.on('swapDoc', function () { clearSpellcheckMarks(editor); scheduleSpellcheck(editor, 0); });
        registerSpellcheckControls();
    });
})(window.Lx);
