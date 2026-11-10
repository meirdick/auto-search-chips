// Home page demo. The parse results are recorded from the package.
// The filters run here, in the browser, on the sample posts.
(function () {
  const data = window.SEARCH_CHIPS_DEMO;
  const root = document.querySelector("[data-demo]");
  if (!data || !root) return;

  const el = (name) => root.querySelector(`[data-${name}]`);
  const box = el("query");
  const row = el("chips");
  const body = el("rows");
  const count = el("count");
  const examples = el("examples");
  const still = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  const kinds = { type: "enum", "author.name": "relation", views: "number", published_at: "date", is_featured: "boolean" };
  const column = { type: "y", "author.name": "a", views: "v", published_at: "d", is_featured: "f", title: "t" };

  let state = null;
  let run = 0;

  function matches(post, chip) {
    const value = post[column[chip.field]];
    const day = (v) => String(v).slice(0, 10);
    let hit;
    switch (chip.operator) {
      case "equals": hit = String(value).toLowerCase() === String(chip.value).toLowerCase(); break;
      case "contains": hit = String(value).toLowerCase().includes(String(chip.value).toLowerCase()); break;
      case "gt": hit = value > chip.value; break;
      case "gte": hit = value >= chip.value; break;
      case "lt": hit = value < chip.value; break;
      case "lte": hit = value <= chip.value; break;
      case "between":
        hit = chip.field === "published_at"
          ? value >= day(chip.value[0]) && value <= day(chip.value[1])
          : value >= chip.value[0] && value <= chip.value[1];
        break;
      case "after": hit = String(chip.value).length > 10 ? value > day(chip.value) : value >= day(chip.value); break;
      case "before": hit = value < day(chip.value); break;
      case "is_true": hit = value === true; break;
      case "is_false": hit = value === false; break;
      default: hit = true;
    }
    return chip.negated ? !hit : hit;
  }

  function results() {
    const words = state.leftover.toLowerCase().split(/\s+/).filter(Boolean);
    let posts = data.posts.filter(
      (post) =>
        state.chips.every((chip) => matches(post, chip)) &&
        words.every((word) => post.t.toLowerCase().includes(word) || post.a.toLowerCase().includes(word)),
    );
    if (state.sort) {
      const key = column[state.sort.field];
      const sign = state.sort.direction === "desc" ? -1 : 1;
      posts = [...posts].sort((a, b) => (a[key] > b[key] ? sign : a[key] < b[key] ? -sign : 0));
    }
    return posts;
  }

  function sentence(shown) {
    const query = state.query.slice(0, shown);
    const marks = [];
    for (const chip of state.chips) {
      for (const span of chip.spans) marks.push({ ...span, kind: kinds[chip.field], id: chip.id });
    }
    marks.sort((a, b) => a.start - b.start);

    box.textContent = "";
    let at = 0;
    for (const mark of marks) {
      if (mark.start >= query.length) break;
      if (mark.start > at) box.append(query.slice(at, mark.start));
      const word = document.createElement("span");
      word.className = "word";
      word.dataset.kind = mark.kind;
      word.dataset.chip = mark.id;
      word.textContent = query.slice(mark.start, Math.min(mark.end, query.length));
      box.append(word);
      at = Math.min(mark.end, query.length);
    }
    if (at < query.length) box.append(query.slice(at));
    if (shown < state.query.length) {
      const caret = document.createElement("span");
      caret.className = "caret";
      box.append(caret);
    }
  }

  function chip(label, kind, remove, name) {
    const item = document.createElement("li");
    item.className = "chip";
    if (kind) item.dataset.kind = kind;
    const text = document.createElement("span");
    text.textContent = label;
    const button = document.createElement("button");
    button.type = "button";
    button.setAttribute("aria-label", `Remove ${name}`);
    button.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6.3 5.2a.75.75 0 0 0-1.1 1.1L8.9 10l-3.7 3.7a.75.75 0 1 0 1.1 1.1L10 11.1l3.7 3.7a.75.75 0 1 0 1.1-1.1L11.1 10l3.7-3.7a.75.75 0 0 0-1.1-1.1L10 8.9 6.3 5.2Z"/></svg>';
    button.addEventListener("click", remove);
    item.append(text, button);
    return item;
  }

  function render(stagger) {
    sentence(state.query.length);

    row.textContent = "";
    const items = [
      ...state.chips.map((c) =>
        chip(c.label, kinds[c.field], () => { state.chips = state.chips.filter((x) => x.id !== c.id); render(false); }, `filter: ${c.label}`),
      ),
    ];
    if (state.leftover) {
      items.push(chip(`Text: “${state.leftover}”`, "text", () => { state.leftover = ""; render(false); }, "text search"));
    }
    if (state.sort) {
      items.push(chip(`Sort: ${state.sort.label}`, "sort", () => { state.sort = null; render(false); }, "sort"));
    }
    items.forEach((item, i) => {
      if (stagger && !still) item.style.animationDelay = `${i * 90}ms`;
      else item.style.animation = "none";
      row.append(item);
    });

    const posts = results();
    count.textContent = `${posts.length} of ${data.posts.length} posts`;
    body.textContent = "";
    if (posts.length === 0) {
      const line = document.createElement("tr");
      line.innerHTML = '<td colspan="5" class="none">No post matches. Remove a chip to widen the search.</td>';
      body.append(line);
      return;
    }
    for (const post of posts.slice(0, 6)) {
      const line = document.createElement("tr");
      for (const cell of [post.t, post.y, post.a, post.v.toLocaleString("en-US"), post.d]) {
        const td = document.createElement("td");
        td.textContent = cell;
        line.append(td);
      }
      body.append(line);
    }
    if (posts.length > 6) {
      const line = document.createElement("tr");
      line.innerHTML = `<td colspan="5" class="more">and ${posts.length - 6} more</td>`;
      body.append(line);
    }
  }

  function play(index, animate) {
    const example = data.examples[index];
    const mine = ++run;
    state = { query: example.query, chips: example.chips.map((c) => ({ ...c })), leftover: example.leftover, sort: example.sort };
    examples.querySelectorAll("button").forEach((b, i) => b.setAttribute("aria-pressed", String(i === index)));

    if (!animate || still) return render(true);

    row.textContent = "";
    count.textContent = "";
    let shown = 0;
    (function type() {
      if (mine !== run) return;
      sentence(shown);
      if (shown >= state.query.length) return render(true);
      shown += 1;
      setTimeout(type, 22);
    })();
  }

  data.examples.forEach((example, i) => {
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = example.query;
    button.addEventListener("click", () => play(i, true));
    examples.append(button);
  });

  row.addEventListener("mouseover", (event) => {
    const item = event.target.closest(".chip");
    root.dataset.focus = item ? [...row.children].indexOf(item) : "";
    box.querySelectorAll(".word").forEach((word) => {
      const hit = item && state.chips[[...row.children].indexOf(item)]?.id === word.dataset.chip;
      word.classList.toggle("lit", Boolean(hit));
    });
  });
  row.addEventListener("mouseleave", () => box.querySelectorAll(".word").forEach((word) => word.classList.remove("lit")));

  play(0, true);
})();
