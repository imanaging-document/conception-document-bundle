(() => {
  const state = window.documentEditorLegacy;
  if (!state || window.documentEditorLegacyBound) return;
  window.documentEditorLegacyBound = true;
  const isWrite = (url) =>
    /\/(?:toggle-actif|save-[^/?]+|add-page|import-yaml|import-background-assisted)(?:[/?]|$)|\/(?:page|bloc|condition)\/.*\/(?:add|remove|duplicate)(?:[/?]|$)|\/conceptions\/new(?:[/?]|$)/.test(
      new URL(url, location.href).pathname,
    );
  const fields = (form) => {
    for (const [name, value] of Object.entries({
      _document_csrf: state.csrf,
      _document_revisions: JSON.stringify(state.revisions),
    })) {
      let input = form.querySelector(`input[name="${name}"]`);
      if (!input) {
        input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        form.append(input);
      }
      input.value = value;
    }
  };
  document.addEventListener(
    "submit",
    (event) => {
      if (event.target instanceof HTMLFormElement) fields(event.target);
    },
    true,
  );
  document.addEventListener("click", (event) => {
    const link = event.target.closest("a[href]");
    if (
      !link ||
      new URL(link.href).origin !== location.origin ||
      !isWrite(link.href)
    )
      return;
    event.preventDefault();
    const form = document.createElement("form");
    form.method = "post";
    form.action = link.href;
    fields(form);
    document.body.append(form);
    form.submit();
  });
  if (window.jQuery) {
    jQuery.ajaxPrefilter((options) => {
      const url = new URL(options.url, location.href);
      if (url.origin !== location.origin) return;
      if (isWrite(url.href) || options.type.toUpperCase() !== "GET") {
        if (isWrite(url.href)) options.type = "POST";
        options.headers = {
          ...options.headers,
          "X-Document-CSRF": state.csrf,
          "X-Document-Revisions": JSON.stringify(state.revisions),
        };
      }
    });
    jQuery(document).ajaxComplete((_event, xhr) => {
      const value = xhr.getResponseHeader("X-Document-Revisions");
      if (value) state.revisions = JSON.parse(value);
    });
  }
})();
