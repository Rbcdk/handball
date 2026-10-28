import { mountViewers } from './match/viewer';
import { mountLineup } from './lineup';

// Tactics page: show the explanation for the selected option.
function bindTacticHelp() {
  document.querySelectorAll<HTMLElement>('[data-help]').forEach((help) => {
    const name = help.dataset.help!;
    document.querySelectorAll<HTMLInputElement>(`input[name="${name}"]`).forEach((input) => {
      input.addEventListener('change', () => {
        help.querySelectorAll<HTMLElement>('[data-for]').forEach((s) => (s.hidden = s.dataset.for !== input.value));
      });
    });
  });
}

// Light/dark switch. Dark is the default; the choice is kept for a year in a cookie
// so the server renders the right theme on the next page load without a flash.
function bindThemeSwitch() {
  document.querySelectorAll<HTMLButtonElement>('[data-theme-switch]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      document.cookie = `theme=${next}; path=/; max-age=31536000; SameSite=Lax`;
      btn.setAttribute('aria-pressed', String(next === 'dark'));
      const label = btn.querySelector('[data-theme-label]');
      if (label) label.textContent = next === 'dark' ? 'Light mode' : 'Dark mode';
      document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', next);
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', next === 'dark' ? '#143c66' : '#1f5a96');
    });
  });
}

// Tactics page: move the defenders and highlight the attack zone when an option changes.
function bindFormation() {
  const box = document.querySelector<HTMLElement>('[data-formation]');
  if (!box) return;
  const spots: Record<string, [number, number][]> = JSON.parse(box.dataset.formation!);
  document.querySelectorAll<HTMLInputElement>('input[name="defense"]').forEach((input) => input.addEventListener('change', () => {
    spots[input.value]?.forEach(([x, y], i) => {
      box.querySelector<SVGGElement>(`[data-man="${i}"]`)!.style.transform = `translate(${x}px, ${y}px)`;
    });
    box.querySelector('[data-defense-label]')!.textContent = input.value;
    box.querySelector('[data-defense-svg]')!.setAttribute('aria-label', `Defense system ${input.value}`);
  }));
  document.querySelectorAll<HTMLInputElement>('input[name="focus"]').forEach((input) => input.addEventListener('change', () => {
    box.querySelector<HTMLElement>('[data-focus-svg]')!.dataset.focus = input.value;
    box.querySelector('[data-focus-label]')!.textContent = input.nextElementSibling?.textContent ?? input.value;
  }));
}

bindThemeSwitch();
bindFormation();
bindTacticHelp();
mountViewers();
mountLineup();
