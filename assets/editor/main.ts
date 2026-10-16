import "./editor.css";
import { Editor, Node, Extension, mergeAttributes } from "@tiptap/core";
import StarterKit from "@tiptap/starter-kit";
import {
  TextStyle,
  Color,
  FontFamily,
  FontSize,
} from "@tiptap/extension-text-style";
import TextAlign from "@tiptap/extension-text-align";
import Highlight from "@tiptap/extension-highlight";
import { TableKit } from "@tiptap/extension-table";
import * as pdfjs from "pdfjs-dist";
import { align, mm, snapBox } from "./geometry.js";
import { History } from "./history.js";
pdfjs.GlobalWorkerOptions.workerSrc = new URL(
  "pdfjs-dist/build/pdf.worker.min.mjs",
  import.meta.url,
).toString();
const Variable = Node.create({
  name: "variable",
  group: "inline",
  inline: true,
  atom: true,
  addAttributes() {
    return {
      token: { default: "", parseHTML: (e) => e.getAttribute("data-variable") },
    };
  },
  parseHTML() {
    return [{ tag: "span[data-variable]" }];
  },
  renderHTML({ node }) {
    return [
      "span",
      {
        "data-variable": node.attrs.token,
        class: "variable-chip",
        contenteditable: "false",
      },
      node.attrs.token,
    ];
  },
  renderText({ node }) {
    return node.attrs.token;
  },
});
const PageBreak = Node.create({
  name: "pageBreak",
  group: "block",
  atom: true,
  parseHTML() {
    return [{ tag: "div[data-page-break]" }];
  },
  renderHTML() {
    return ["div", { "data-page-break": "true", class: "page-break" }];
  },
});
const RetainedStyles = Extension.create({
  name: "retainedStyles",
  addGlobalAttributes() {
    return [
      {
        types: [
          "paragraph",
          "heading",
          "table",
          "tableRow",
          "tableCell",
          "tableHeader",
          "textStyle",
          "bulletList",
          "orderedList",
          "listItem",
        ],
        attributes: {
          legacyStyle: {
            default: null,
            parseHTML: (e) => e.getAttribute("style"),
            renderHTML: (a) => (a.legacyStyle ? { style: a.legacyStyle } : {}),
          },
        },
      },
    ];
  },
});
const newKey = () =>
  Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) =>
    b.toString(16).padStart(2, "0"),
  ).join("");
const copy = <T>(x: T): T => structuredClone(x);
const escape = (x: any) =>
  String(x ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ]!,
  );
class DocumentEditor {
  root: HTMLElement;
  api: string;
  csrf: string;
  state: any;
  definition: any;
  page = 0;
  selected: string[] = [];
  history = new History();
  sequence = 0;
  savedSequence = 0;
  draftConflict = false;
  change = 0;
  timer: any;
  renderTimer: any;
  frame: HTMLIFrameElement;
  overlay: HTMLElement;
  rich: Editor | null = null;
  richKey: string | null = null;
  pendingEdit: string | null = null;
  busy = false;
  saving: Promise<any> | null = null;
  scale = 1;
  zoom = 100;
  grid = false;
  snap = true;
  pdfGeneration = 0;
  assets = new Map();
  htmlGeneration = 0;
  frameObserver: ResizeObserver | null = null;
  renderError: string | null = null;
  ready: Promise<void>;
  constructor(root: HTMLElement) {
    this.root = root;
    this.api = root.dataset.api!;
    this.csrf = root.dataset.csrf!;
    this.shell();
    this.root
      .querySelectorAll<
        HTMLButtonElement | HTMLInputElement | HTMLSelectElement
      >("button,input,select")
      .forEach((el) => (el.disabled = true));
    this.frame = root.querySelector("iframe")!;
    this.overlay = root.querySelector(".overlay")!;
    this.bind();
    window.addEventListener("message", (event) => {
      if (
        event.source === this.frame.contentWindow &&
        event.data?.type === "conception-document-ready"
      ) {
        this.resize();
        this.draw();
        if (!this.rich) this.selectionControls();
        this.diagnostics();
      }
    });
    window.addEventListener("message", (event) => {
      if (
        event.source === this.frame.contentWindow &&
        event.data?.type === "conception-document-error"
      ) {
        this.renderError = String(event.data.message);
        this.status(this.renderError, true);
        this.diagnostics();
      }
    });
    this.ready = this.load();
  }
  status(text: string, error = false) {
    const el = this.root.querySelector(".status")!;
    el.textContent = text;
    el.classList.toggle("error", error);
  }
  async request(action: string, payload?: any, form?: FormData) {
    const response = await fetch(this.api.replace("ACTION", action), {
      method: payload !== undefined || form ? "POST" : "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        "X-CSRF-Token": this.csrf,
        ...(!form && payload !== undefined
          ? { "Content-Type": "application/json" }
          : {}),
      },
      body:
        form || (payload !== undefined ? JSON.stringify(payload) : undefined),
    });
    if (!response.headers.get("content-type")?.includes("application/json"))
      throw new Error("Réponse du serveur invalide ou session expirée.");
    const data = await response.json();
    if (!response.ok) {
      const error: any = new Error(data.message || "Opération impossible.");
      error.status = response.status;
      throw error;
    }
    return data;
  }
  shell() {
    this.root.innerHTML = `<header><div class="heading"><strong id="doc-title">Chargement de la conception…</strong><div class="actions"><span class="status" role="status" aria-live="polite">Chargement…</span><button data-action="undo" title="Annuler">↶</button><button data-action="redo" title="Rétablir">↷</button><button data-action="versions">Versions</button><button data-action="archive">Import / export</button><button data-action="publish" class="primary">Enregistrer le modèle</button><button data-action="pdf">Vérifier le PDF</button><a href="${escape(this.root.dataset.classic)}">Mode avancé</a></div></div>
 <div class="tools"><button data-add="text">+ Texte</button><button data-add="table">+ Tableau</button><button data-add="shape">+ Forme</button><button data-add="image">+ Image</button><button data-action="library">Bibliothèque</button><button data-add="page">+ Page</button><button data-action="page-up">Page ←</button><button data-action="page-down">Page →</button><button data-action="page-delete">Retirer la page</button><label>Format <select id="format"><option>A4</option><option>A3</option></select></label><label>Orientation <select id="orientation"><option value="portrait">Portrait</option><option value="landscape">Paysage</option></select></label><label>Composition <select id="mode"><option value="free">Blocs libres</option><option value="flow">Corps paginé</option></select></label><label>Impression <select id="print-mode"><option value="simple">Feuille simple</option><option value="booklet">Livret A3</option></select></label><label><input type="checkbox" id="rotate-back">Tourner le verso</label><label>Zoom <input type="number" id="zoom" min="25" max="200" value="100">%</label><label><input type="checkbox" id="grid">Grille</label><label><input type="checkbox" id="snap" checked>Guides magnétiques</label></div>
 <div class="tools prosemirror-menu"><button data-action="edit">Écrire</button><label>Police <select data-style="font-family"><option value="Arial, sans-serif">Arial</option><option value="Georgia, serif">Georgia</option><option value="Times New Roman, serif">Times New Roman</option></select></label><label>Taille <input type="number" data-style="font-size" min="6" max="120" value="10">pt</label><button data-rich="toggleBold"><b>G</b></button><button data-rich="toggleItalic"><i>I</i></button><button data-rich="toggleUnderline"><u>S</u></button><button data-rich="toggleBulletList">• Liste</button><button data-rich="toggleOrderedList">1. Liste</button><button data-action="indent">Retrait +</button><button data-action="outdent">Retrait −</button><label>Interligne <select data-style="line-height"><option>1</option><option selected>1.25</option><option>1.5</option><option>2</option></select></label><label>Avant <input type="number" data-style="margin-top" value="0" min="0" max="50">mm</label><label>Après <input type="number" data-style="margin-bottom" value="0" min="0" max="50">mm</label><label>Alignement <select data-style="text-align"><option value="left">Gauche</option><option value="center">Centré</option><option value="right">Droite</option><option value="justify">Justifié</option></select></label><label>Texte <input type="color" data-style="color" value="#111111"></label><label>Fond <input type="color" data-style="background-color" value="#ffffff"></label><button data-rich="toggleHighlight">Surligner</button><button data-action="link">Lien</button><input id="field-search" placeholder="Rechercher un champ" aria-label="Rechercher un champ"><select id="field" class="field-picker" aria-label="Insérer un champ"><option value="">Insérer un champ…</option></select><button data-action="heading">Titre / paragraphe</button><button data-action="keep-next">Garder avec le suivant</button><button data-action="break">Saut de page</button></div>
 <div class="tools"><label>Aligner sur <select id="align-target"><option value="reference">Dernier bloc sélectionné</option><option value="page">Page</option><option value="margins">Marges</option></select></label>${[
   ["left", "Gauche"],
   ["center", "Centre"],
   ["right", "Droite"],
   ["top", "Haut"],
   ["middle", "Milieu"],
   ["bottom", "Bas"],
   ["distribute-x", "Répartir ↔"],
   ["distribute-y", "Répartir ↕"],
 ]
   .map(([v, l]) => `<button data-align="${v}">${l}</button>`)
   .join(
     "",
   )}<button data-action="front">Premier plan</button><button data-action="back">Arrière-plan</button><button data-action="lock">Verrouiller / déverrouiller</button><button data-action="duplicate">Dupliquer</button><button data-action="delete">Supprimer</button><button data-action="image">Remplacer l’image</button><button data-action="table">Réglages tableau</button></div>
 <div class="hint"><span id="selection-label">Aucun bloc sélectionné</span><span id="pdf-status">PDF non vérifié · Double-clic pour écrire · Ctrl/Cmd + clic : sélection multiple</span></div></header>
 <div class="workspace"><aside><h2>Pages</h2><div class="page-buttons"></div><h2>Blocs</h2><div class="block-list"></div><h2>Position et dimensions</h2><div class="geometry">${[
   ["left", "X"],
   ["top", "Y"],
   ["width", "Largeur"],
   ["height", "Hauteur"],
 ]
   .map(
     ([v, l]) =>
       `<label>${l} (mm)<input type="number" data-geometry="${v}" step="0.1" min="0"></label>`,
   )
   .join(
     "",
   )}</div><label>Zone <select id="zone"><option value="body">Corps</option><option value="header">En-tête répété</option><option value="footer">Pied répété</option></select></label><div class="shape-options hidden"><label>Forme <select id="shape-type"><option value="rectangle">Rectangle</option><option value="cercle">Ellipse</option><option value="ligne_horizontale">Ligne horizontale</option><option value="ligne_verticale">Ligne verticale</option><option value="triangle">Triangle</option></select></label></div><div class="image-options hidden"><h2>Image</h2><label>Dimensions <select id="image-auto-size"><option value="height">Hauteur automatique</option><option value="width">Largeur automatique</option><option value="none">Cadre fixe / recadrage</option></select></label><label>Affichage <select id="image-fit"><option value="contain">Afficher entièrement</option><option value="cover">Remplir le cadre</option></select></label><label>Cadrage X (%) <input id="crop-x" type="number" min="0" max="100" value="50"></label><label>Cadrage Y (%) <input id="crop-y" type="number" min="0" max="100" value="50"></label><label>Opacité <input data-style="opacity" type="number" min="0" max="1" step="0.1" value="1"></label><button data-action="background">Fond de page</button></div><h2>Marges du document</h2><div class="geometry">${["top", "right", "bottom", "left"].map((k, i) => `<label>${["Haut", "Droite", "Bas", "Gauche"][i]} (mm)<input data-margin="${k}" type="number" min="0" max="100" value="15"></label>`).join("")}</div><div id="diagnostics"></div></aside><main><div id="flow-edit"></div><div class="sheet-shell"><iframe class="sheet-frame" title="Document HTML isolé" sandbox="allow-same-origin allow-scripts"></iframe><div class="overlay"></div></div></main></div><input type="file" id="image-file" accept="image/png,image/jpeg,image/webp,image/svg+xml" hidden>`;
  }
  async load() {
    try {
      this.state = await this.request("document");
      this.definition = this.state.definition;
      this.sequence = this.state.sequence;
      this.savedSequence = this.state.sequence;
      this.root
        .querySelectorAll<
          HTMLButtonElement | HTMLInputElement | HTMLSelectElement
        >("button,input,select")
        .forEach((el) => (el.disabled = false));
      const select = this.root.querySelector(
        "[data-style=font-family]",
      ) as HTMLSelectElement;
      for (const family of [
        "Montserrat",
        "Libre Barcode 128",
        ...(this.state.fonts || []),
      ]) {
        const option = document.createElement("option");
        option.value = family;
        option.textContent = family;
        select.append(option);
      }
      this.renderControls();
      await this.render();
      this.draftConflict = this.state.baseVersion !== this.state.version;
      this.status(
        this.draftConflict
          ? "Le modèle a changé : brouillon récupéré. Exportez-le ou restaurez une version."
          : this.state.draft
            ? "Brouillon récupéré"
            : "Modèle enregistré",
        this.draftConflict,
      );
    } catch (e: any) {
      this.status(e.message, true);
    }
  }
  blocks() {
    this.page = Math.min(this.page, this.definition.pages.length - 1);
    return this.definition.pages[this.page].blocks;
  }
  block(key = this.selected.at(-1)) {
    return this.blocks().find((b: any) => b.key === key);
  }
  locked(b: any) {
    return ["1", "true", "yes", "on"].includes(
      String(b.style["--bundle-lock"] || "").toLowerCase(),
    );
  }
  bind() {
    this.root.addEventListener("click", (e) => {
      const target = (e.target as HTMLElement).closest(
        "button",
      ) as HTMLButtonElement;
      if (!target) return;
      if (target.dataset.action) this.action(target.dataset.action);
      if (target.dataset.add) this.add(target.dataset.add);
      if (target.dataset.align) this.align(target.dataset.align);
      if (target.dataset.rich) this.command(target.dataset.rich);
    });
    this.root
      .querySelectorAll(
        '[data-rich], [data-action="table"], [data-action="link"], [data-action="break"]',
      )
      .forEach((b) =>
        b.addEventListener("mousedown", (e) => e.preventDefault()),
      );
    this.root
      .querySelectorAll("[data-style]")
      .forEach((el) =>
        el.addEventListener(el.tagName === "INPUT" ? "input" : "change", () =>
          this.setStyle(el as HTMLInputElement),
        ),
      );
    this.root.querySelectorAll("[data-geometry]").forEach((el) =>
      el.addEventListener("change", () => {
        const input = el as HTMLInputElement;
        const value = Number(input.value);
        if (!Number.isFinite(value) || value < 0) return;
        this.endEdit();
        this.mutate(() =>
          this.selected.forEach((k) => {
            const b = this.block(k);
            if (b && !this.locked(b)) {
              const axis = input.dataset.geometry!;
              if (
                b.type === "bloc_image" &&
                ["width", "height"].includes(axis) &&
                this.imageAutoSize(b) !== "none"
              ) {
                b.style[axis === "width" ? "height" : "width"] = "auto";
              }
              b.style[axis] = value + "mm";
            }
          }),
        );
      }),
    );
    for (const id of [
      "format",
      "orientation",
      "mode",
      "print-mode",
      "rotate-back",
    ])
      this.root.querySelector("#" + id)!.addEventListener("change", () => {
        this.endEdit();
        this.mutate(() => {
          this.definition.pageSpec.format = (
            this.root.querySelector("#format") as HTMLSelectElement
          ).value;
          this.definition.pageSpec.orientation = (
            this.root.querySelector("#orientation") as HTMLSelectElement
          ).value;
          this.definition.mode = (
            this.root.querySelector("#mode") as HTMLSelectElement
          ).value;
          this.definition.printProfile = {
            mode: (this.root.querySelector("#print-mode") as HTMLSelectElement)
              .value,
            rotateBack: (
              this.root.querySelector("#rotate-back") as HTMLInputElement
            ).checked,
          };
          if (this.definition.printProfile.mode === "booklet") {
            this.definition.pageSpec.format = "A4";
            this.definition.pageSpec.orientation = "portrait";
          }
        });
        this.renderControls();
      });
    this.root.querySelectorAll("[data-margin]").forEach((el) =>
      el.addEventListener("change", () => {
        const input = el as HTMLInputElement;
        this.mutate(
          () =>
            (this.definition.pageSpec.margins[input.dataset.margin!] = Math.max(
              0,
              Math.min(100, Number(input.value)),
            )),
        );
      }),
    );
    this.root.querySelector("#zoom")!.addEventListener("input", (e) => {
      this.zoom = Math.max(
        25,
        Math.min(200, Number((e.target as HTMLInputElement).value)),
      );
      this.resize();
    });
    this.root.querySelector("#grid")!.addEventListener("change", (e) => {
      this.grid = (e.target as HTMLInputElement).checked;
      this.overlay.classList.toggle("grid", this.grid);
    });
    this.root
      .querySelector("#snap")!
      .addEventListener(
        "change",
        (e) => (this.snap = (e.target as HTMLInputElement).checked),
      );
    this.root
      .querySelector("#field-search")!
      .addEventListener("input", () => this.fields());
    this.root.querySelector("#field")!.addEventListener("change", (e) => {
      const input = e.target as HTMLSelectElement;
      if (!input.value) return;
      this.beginEdit();
      this.rich
        ?.chain()
        .focus()
        .insertContent({ type: "variable", attrs: { token: input.value } })
        .run();
      input.value = "";
    });
    this.root.querySelector("#shape-type")!.addEventListener("change", (e) =>
      this.mutate(() => {
        const b = this.block();
        if (!b || this.locked(b) || b.type !== "formes_predefinies") return;
        const shape = (e.target as HTMLSelectElement).value;
        b.shape = shape;
        delete b.style["clip-path"];
        b.style["border-radius"] = shape === "cercle" ? "50%" : "0";
        if (shape === "ligne_horizontale") b.style.height = "0.5mm";
        if (shape === "ligne_verticale") b.style.width = "0.5mm";
        if (shape === "triangle")
          b.style["clip-path"] = "polygon(50% 0,100% 100%,0 100%)";
      }),
    );
    this.root.querySelector("#zone")!.addEventListener("change", (e) =>
      this.mutate(() => {
        if (this.block() && !this.locked(this.block()))
          this.block().zone = (e.target as HTMLSelectElement).value;
      }),
    );
    this.root
      .querySelector("#image-auto-size")!
      .addEventListener("change", (e) => {
        const b = this.block();
        if (!b || b.type !== "bloc_image" || this.locked(b)) return;
        const box = this.box(b.key);
        const mode = (e.target as HTMLSelectElement).value;
        this.mutate(() => {
          b.style.width = mode === "width" ? "auto" : box.w.toFixed(2) + "mm";
          b.style.height = mode === "height" ? "auto" : box.h.toFixed(2) + "mm";
          if (mode !== "none") b.fit = "contain";
        });
      });
    for (const id of ["image-fit", "crop-x", "crop-y"])
      this.root.querySelector("#" + id)!.addEventListener("change", () =>
        this.mutate(() => {
          const b = this.block();
          if (!b || b.type !== "bloc_image" || this.locked(b)) return;
          const fit = (
            this.root.querySelector("#image-fit") as HTMLSelectElement
          ).value;
          if (fit === "cover" && this.imageAutoSize(b) !== "none") {
            const box = this.box(b.key);
            b.style.width = box.w.toFixed(2) + "mm";
            b.style.height = box.h.toFixed(2) + "mm";
          } else if (fit === "contain" && b.fit === "cover") {
            b.style.height = "auto";
          }
          b.fit = fit;
          b.crop = `${Math.max(0, Math.min(100, Number((this.root.querySelector("#crop-x") as HTMLInputElement).value)))}% ${Math.max(0, Math.min(100, Number((this.root.querySelector("#crop-y") as HTMLInputElement).value)))}%`;
        }),
      );
    this.root.querySelector("#image-file")!.addEventListener("change", (e) => {
      const f = (e.target as HTMLInputElement).files?.[0];
      if (f) this.upload(f);
      (e.target as HTMLInputElement).value = "";
    });
    const main = this.root.querySelector("main")!;
    main.addEventListener("click", (e) => {
      if (e.target === main) this.deselect();
    });
    main.addEventListener("dragover", (e) => e.preventDefault());
    main.addEventListener("drop", (e) => {
      e.preventDefault();
      const file = (e as DragEvent).dataTransfer?.files[0];
      if (file) {
        this.selected = [];
        this.upload(file);
      }
    });
    document.addEventListener("keydown", (e) => this.key(e), true);
    window.addEventListener("resize", () => this.resize());
    window.addEventListener("beforeunload", (e) => {
      if (this.change) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  }
  dimensions() {
    let [w, h] =
      this.definition.pageSpec.format === "A3" ? [297, 420] : [210, 297];
    return this.definition.pageSpec.orientation === "landscape"
      ? [h, w]
      : [w, h];
  }
  renderControls() {
    if (!this.definition) return;
    let fonts = document.getElementById("document-source-fonts");
    if (!fonts) {
      fonts = document.createElement("style");
      fonts.id = "document-source-fonts";
      document.head.append(fonts);
    }
    if (fonts.textContent !== this.definition.fontResources)
      fonts.textContent = this.definition.fontResources || "";
    this.root.querySelector("#doc-title")!.textContent = this.definition.label;
    for (const [id, v] of Object.entries({
      format: this.definition.pageSpec.format,
      orientation: this.definition.pageSpec.orientation,
      mode: this.definition.mode,
      "print-mode": this.definition.printProfile.mode,
    }))
      (this.root.querySelector("#" + id) as HTMLInputElement).value =
        v as string;
    this.root.querySelectorAll("[data-margin]").forEach((el) => {
      const input = el as HTMLInputElement;
      input.value = String(
        this.definition.pageSpec.margins?.[input.dataset.margin!] ?? 15,
      );
    });
    (this.root.querySelector("#rotate-back") as HTMLInputElement).checked =
      this.definition.printProfile.rotateBack;
    this.root.querySelector(".page-buttons")!.innerHTML = this.definition.pages
      .map(
        (p: any, i: number) =>
          `<button data-page="${i}" class="${i === this.page ? "selected" : ""}">${i + 1}</button>`,
      )
      .join("");
    this.root.querySelectorAll("[data-page]").forEach((el) =>
      el.addEventListener("click", () => {
        this.endEdit();
        this.page = Number((el as HTMLElement).dataset.page);
        this.selected = [];
        this.renderControls();
        this.render();
      }),
    );
    this.root.querySelector(".block-list")!.innerHTML = this.blocks()
      .map(
        (b: any) =>
          `<button class="block ${this.selected.includes(b.key) ? "selected" : ""}" data-block="${escape(b.key)}">${escape(b.label)}${this.locked(b) ? " 🔒" : ""}<div class="badges">${escape(b.type)}</div></button>`,
      )
      .join("");
    this.root
      .querySelectorAll("[data-block]")
      .forEach((el) =>
        el.addEventListener("click", (e) =>
          this.select(
            (el as HTMLElement).dataset.block!,
            (e as MouseEvent).ctrlKey || (e as MouseEvent).metaKey,
          ),
        ),
      );
    this.fields();
    this.selectionControls();
  }
  fields() {
    const search = (
      this.root.querySelector("#field-search") as HTMLInputElement
    ).value.toLowerCase();
    const fields = this.state.fields || [];
    (this.root.querySelector("#field") as HTMLSelectElement).innerHTML =
      '<option value="">Insérer un champ…</option>' +
      fields
        .filter((f: any) => (f.label + f.token).toLowerCase().includes(search))
        .map(
          (f: any) =>
            `<option value="${escape(f.token)}">${escape(f.label)} · ${escape(f.token)}</option>`,
        )
        .join("");
  }
  imageAutoSize(b: any) {
    return b.style.width === "auto"
      ? "width"
      : b.style.height === "auto"
        ? "height"
        : "none";
  }
  updateImageElement(el: HTMLElement, b: any) {
    const img = el.querySelector("img");
    if (!img || b.type !== "bloc_image") return;
    const mode = this.imageAutoSize(b);
    el.style.width = mode === "width" ? "max-content" : b.style.width;
    img.style.width = mode === "width" ? "auto" : "100%";
    img.style.height =
      mode === "height" ? "auto" : mode === "width" ? b.style.height : "100%";
  }
  selectionControls() {
    const b = this.block();
    this.root.querySelector("#selection-label")!.textContent =
      this.selected.length > 1
        ? `${this.selected.length} blocs sélectionnés`
        : b?.label || "Aucun bloc sélectionné";
    this.root.querySelectorAll("[data-geometry]").forEach((el) => {
      const i = el as HTMLInputElement;
      const axis = i.dataset.geometry!;
      const automatic = b?.type === "bloc_image" && b.style[axis] === "auto";
      const box = automatic ? this.box(b.key) : null;
      const value = box
        ? axis === "width"
          ? box.w
          : box.h
        : b
          ? mm(b.style[axis])
          : 0;
      i.value = b ? String(Math.round(value * 100) / 100) : "";
      i.title = automatic
        ? "Dimension automatique. Modifier cette valeur rend l’autre dimension automatique."
        : "";
      i.disabled = !b || this.locked(b);
    });
    this.root
      .querySelector(".image-options")!
      .classList.toggle("hidden", b?.type !== "bloc_image");
    if (b) {
      if (b.asset) {
        const auto = this.root.querySelector(
          "#image-auto-size",
        ) as HTMLSelectElement;
        auto.value = this.imageAutoSize(b);
        auto.disabled = this.locked(b);
        (this.root.querySelector("#image-fit") as HTMLSelectElement).value =
          b.fit || "contain";
        const crop = (b.crop || "50% 50%").split(" ");
        (this.root.querySelector("#crop-x") as HTMLInputElement).value = String(
          parseFloat(crop[0]),
        );
        (this.root.querySelector("#crop-y") as HTMLInputElement).value = String(
          parseFloat(crop[1]),
        );
        for (const id of ["crop-x", "crop-y"]) {
          (this.root.querySelector("#" + id) as HTMLInputElement).disabled =
            this.locked(b) ||
            this.imageAutoSize(b) !== "none" ||
            b.fit !== "cover";
        }
      }
      (this.root.querySelector("#zone") as HTMLSelectElement).value =
        b.zone || "body";
      this.root.querySelectorAll("[data-style]").forEach((el) => {
        const i = el as HTMLInputElement;
        const val = b.style[i.dataset.style!];
        if (document.activeElement === i) return;
        if (i.dataset.style === "font-size")
          i.value = String(
            /pt$/.test(val)
              ? parseFloat(val)
              : parseFloat(val || "13.333px") * 0.75,
          );
        else if (i.type === "color") {
          if (/^#[a-f0-9]{6}$/i.test(val)) i.value = val;
        } else if (val !== undefined) i.value = val;
      });
    }
  }
  async render() {
    if (this.rich) return;
    this.renderError = null;
    const generation = ++this.htmlGeneration;
    try {
      const response = await fetch(this.api.replace("ACTION", "html"), {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": this.csrf,
        },
        body: JSON.stringify({
          definition: this.definition,
          baseVersion: this.state.baseVersion,
        }),
      });
      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message);
      }
      const html = await response.text();
      if (generation !== this.htmlGeneration || this.rich) return;
      this.frame.onload = () => this.attachFrame();
      this.frame.srcdoc = html;
    } catch (e: any) {
      this.status(e.message, true);
    }
  }
  attachFrame() {
    const doc = this.frame.contentDocument!;
    const sheets = Array.from(doc.querySelectorAll(".document-sheet"));
    if (this.definition.mode === "free")
      sheets.forEach((s: any, i) => {
        if (i !== this.page) s.remove();
      });
    doc.addEventListener("click", (e) => {
      const el = (e.target as HTMLElement).closest<HTMLElement>(
        ".editor-block",
      );
      if (!el || this.richKey === el.dataset.blockKey) return;
      e.preventDefault();
      this.select(
        el.dataset.blockKey!,
        (e as MouseEvent).ctrlKey || (e as MouseEvent).metaKey,
      );
    });
    doc.addEventListener("dblclick", (e) => {
      const el = (e.target as HTMLElement).closest<HTMLElement>(
        ".editor-block",
      );
      if (!el) return;
      e.preventDefault();
      this.select(el.dataset.blockKey!);
      this.beginEdit();
    });
    this.frameObserver?.disconnect();
    this.frameObserver = new ResizeObserver(() => this.resize());
    this.frameObserver.observe(doc.body);
    doc.addEventListener("keydown", (e) => this.key(e), true);
    doc.addEventListener("dragover", (e) => e.preventDefault());
    doc.addEventListener("drop", (e) => {
      e.preventDefault();
      const f = e.dataTransfer?.files[0];
      if (f) {
        this.selected = [];
        this.upload(f);
      }
    });
    this.resize();
    this.draw();
    if (this.pendingEdit && this.selected.includes(this.pendingEdit)) {
      this.pendingEdit = null;
      this.beginEdit();
    }
    setTimeout(() => {
      this.resize();
      this.draw();
      this.diagnostics();
    }, 350);
  }
  resize() {
    if (!this.definition) return;
    const [w, h] = this.dimensions();
    const main = this.root.querySelector("main")!;
    this.scale =
      (Math.min(1, (main.clientWidth - 50) / ((w * 96) / 25.4)) * this.zoom) /
      100;
    const flowHeight =
      this.definition.mode === "flow"
        ? Math.max(
            (h * 96) / 25.4,
            this.frame.contentDocument
              ?.querySelector(".pagedjs_pages")
              ?.getBoundingClientRect().height || 0,
          )
        : (h * 96) / 25.4;
    this.frame.style.width = w + "mm";
    this.frame.style.height = flowHeight + "px";
    this.frame.style.transform = `scale(${this.scale})`;
    const shell = this.root.querySelector(".sheet-shell") as HTMLElement;
    shell.style.width = ((w * 96) / 25.4) * this.scale + "px";
    shell.style.height = flowHeight * this.scale + "px";
    this.overlay.style.width = w + "mm";
    this.overlay.style.height = flowHeight + "px";
    this.overlay.style.transform = `scale(${this.scale})`;
  }
  deselect() {
    if (!this.definition) return;
    this.pendingEdit = null;
    this.endEdit();
    this.selected = [];
    this.renderControls();
    this.draw();
  }
  select(key: string, additive = false) {
    const page = this.definition.pages.findIndex((p: any) =>
      p.blocks.some((b: any) => b.key === key),
    );
    if (page < 0) return;
    if (page !== this.page) {
      this.endEdit();
      this.page = page;
      this.selected = [];
    }
    if (this.richKey !== key) this.endEdit();
    if (additive) {
      this.selected = this.selected.includes(key)
        ? this.selected.filter((k) => k !== key)
        : [...this.selected, key];
    } else this.selected = [key];
    this.renderControls();
    this.draw();
  }
  element(key: string) {
    return this.frame.contentDocument?.querySelector(
      `[data-block-key="${CSS.escape(key)}"]`,
    ) as HTMLElement;
  }
  box(key: string) {
    const b = this.block(key),
      el = this.element(key);
    const computed = el
      ? el.ownerDocument.defaultView!.getComputedStyle(el)
      : null;
    return {
      key,
      x:
        computed && Number.isFinite(parseFloat(computed.left))
          ? (parseFloat(computed.left) * 25.4) / 96
          : mm(b.style.left),
      y:
        computed && Number.isFinite(parseFloat(computed.top))
          ? (parseFloat(computed.top) * 25.4) / 96
          : mm(b.style.top),
      w: el
        ? (el.getBoundingClientRect().width * 25.4) / 96
        : mm(b.style.width),
      h: el
        ? (el.getBoundingClientRect().height * 25.4) / 96
        : mm(b.style.height),
    };
  }
  draw() {
    this.overlay.innerHTML = "";
    this.overlay.classList.toggle("grid", this.grid);
    if (this.definition.mode === "flow") return;
    for (const key of this.selected) {
      const b = this.block(key);
      if (!b) continue;
      const box = this.box(key);
      const div = document.createElement("div");
      div.className = "selection-box";
      Object.assign(div.style, {
        left: box.x + "mm",
        top: box.y + "mm",
        width: box.w + "mm",
        height: box.h + "mm",
      });
      if (!this.locked(b)) {
        for (const type of ["move", "resize"]) {
          const handle = document.createElement("button");
          handle.className = `handle ${type}-handle`;
          handle.textContent = type === "move" ? "↔ Déplacer" : "";
          handle.setAttribute(
            "aria-label",
            type === "move"
              ? "Déplacer la sélection"
              : "Redimensionner le bloc",
          );
          handle.addEventListener("pointerdown", (e) =>
            this.drag(e, key, type),
          );
          div.append(handle);
        }
      }
      this.overlay.append(div);
    }
  }
  drag(event: PointerEvent, key: string, type: string) {
    event.preventDefault();
    this.endEdit(false);
    const before = copy(this.definition),
      start = { x: event.clientX, y: event.clientY };
    const keys =
      type === "move"
        ? this.selected.filter((k) => !this.locked(this.block(k)))
        : [key];
    const boxes = keys.map((k) => this.box(k));
    const others = this.blocks()
      .filter((b: any) => !keys.includes(b.key))
      .map((b: any) => this.box(b.key));
    const [w, h] = this.dimensions();
    others.push({ x: 0, y: 0, w, h });
    const handle = event.currentTarget as HTMLElement;
    handle.setPointerCapture(event.pointerId);
    const move = (e: PointerEvent) => {
      let dx = (((e.clientX - start.x) / this.scale) * 25.4) / 96,
        dy = (((e.clientY - start.y) / this.scale) * 25.4) / 96;
      this.overlay.querySelectorAll(".guide").forEach((g) => g.remove());
      if (type === "move" && this.snap) {
        const anchor = boxes.find((b) => b.key === key) || boxes[0];
        const result = snapBox(
          { ...anchor, x: anchor.x + dx, y: anchor.y + dy },
          others,
          ((6 / this.scale) * 25.4) / 96,
        );
        dx = result.x - anchor.x;
        dy = result.y - anchor.y;
        for (const g of result.guides) {
          const line = document.createElement("div");
          line.className = "guide " + g.axis;
          line.style[g.axis === "x" ? "left" : "top"] = g.value + "mm";
          this.overlay.append(line);
        }
      }
      if (type === "move") {
        dx = Math.max(-Math.min(...boxes.map((b) => b.x)), dx);
        dy = Math.max(-Math.min(...boxes.map((b) => b.y)), dy);
      }
      for (const box of boxes) {
        const block = this.block(box.key),
          el = this.element(box.key);
        if (type === "move") {
          block.style.left = Math.max(0, box.x + dx).toFixed(2) + "mm";
          block.style.top = Math.max(0, box.y + dy).toFixed(2) + "mm";
        } else {
          let nw = Math.max(3, box.w + dx),
            nh = Math.max(3, box.h + dy);
          const auto =
            block.type === "bloc_image" ? this.imageAutoSize(block) : "none";
          if (auto === "height") {
            block.style.width = nw.toFixed(2) + "mm";
            block.style.height = "auto";
          } else if (auto === "width") {
            block.style.width = "auto";
            block.style.height = nh.toFixed(2) + "mm";
          } else {
            if (block.type === "bloc_image" && !e.shiftKey)
              nh = (nw * box.h) / box.w;
            block.style.width = nw.toFixed(2) + "mm";
            block.style.height = nh.toFixed(2) + "mm";
          }
        }
        if (el) {
          Object.assign(el.style, block.style);
          this.updateImageElement(el, block);
        }
      }
      this.selectionControls();
    };
    const end = () => {
      handle.removeEventListener("pointermove", move);
      handle.removeEventListener("pointerup", end);
      handle.removeEventListener("pointercancel", end);
      this.history.push(before, this.definition);
      this.changed(false);
      this.draw();
      this.diagnostics();
    };
    handle.addEventListener("pointermove", move);
    handle.addEventListener("pointerup", end);
    handle.addEventListener("pointercancel", end);
  }
  mutate(fn: () => void, render = true) {
    const before = copy(this.definition);
    fn();
    this.history.push(before, this.definition);
    this.changed(render);
    this.renderControls();
  }
  changed(render = true) {
    this.change++;
    this.status("Modifications en cours…");
    this.root.querySelector("#pdf-status")!.textContent = "PDF à actualiser";
    this.root.querySelectorAll(".pdf-freshness").forEach((el) => {
      el.textContent = "Aperçu obsolète — relancez la vérification";
      el.classList.add("warning");
    });
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.saveDraft().catch(() => {}), 1000);
    if (render && !this.rich) {
      clearTimeout(this.renderTimer);
      this.renderTimer = setTimeout(() => this.render(), 180);
    }
    this.diagnostics();
  }
  async saveDraft() {
    if (this.saving)
      return this.saving.then(() =>
        this.change ? this.saveDraft() : undefined,
      );
    if (!this.change || this.draftConflict) return;
    const change = this.change;
    const payload = {
      definition: copy(this.definition),
      baseVersion: this.state.baseVersion,
      sequence: ++this.sequence,
      expectedSequence: this.savedSequence,
    };
    this.saving = this.request("draft", payload);
    try {
      await this.saving;
      this.savedSequence = payload.sequence;
      if (this.change === change) this.change = 0;
      this.status(
        this.change ? "Modifications en cours…" : "Brouillon sauvegardé",
      );
    } catch (e: any) {
      if (e.status === 409) this.draftConflict = true;
      this.status(e.message, true);
      throw e;
    } finally {
      this.saving = null;
    }
  }
  setStyle(input: HTMLInputElement) {
    const prop = input.dataset.style!,
      b = this.block();
    if (!b || this.locked(b)) return;
    let value = input.value;
    if (prop === "font-size") {
      if (!Number.isFinite(+value) || +value < 6 || +value > 120) return;
      if (this.rich && !this.rich.state.selection.empty) {
        this.rich
          .chain()
          .focus()
          .setFontSize(value + "pt")
          .run();
        return;
      }
      value += "pt";
    }
    if (["margin-bottom", "margin-top"].includes(prop)) value += "mm";
    if (
      this.rich &&
      !this.rich.state.selection.empty &&
      prop === "font-family"
    ) {
      this.rich.chain().focus().setFontFamily(value).run();
      return;
    }
    if (
      this.rich &&
      !this.rich.state.selection.empty &&
      prop === "background-color"
    ) {
      this.rich.chain().focus().setHighlight({ color: value }).run();
      return;
    }
    if (
      this.rich &&
      ["line-height", "margin-top", "margin-bottom"].includes(prop)
    ) {
      const old = this.rich.getAttributes("paragraph").legacyStyle || "";
      this.rich
        .chain()
        .focus()
        .updateAttributes("paragraph", {
          legacyStyle:
            old.replace(new RegExp(prop + ":\\s*[^;]+;?", "g"), "") +
            prop +
            ":" +
            value +
            ";",
        })
        .run();
      return;
    }
    if (this.rich && !this.rich.state.selection.empty && prop === "color") {
      this.rich.chain().focus().setColor(value).run();
      return;
    }
    if (this.rich && prop === "text-align") {
      this.rich.chain().focus().setTextAlign(value).run();
      return;
    }
    this.mutate(
      () =>
        this.selected.forEach((k) => {
          const block = this.block(k);
          if (!this.locked(block)) block.style[prop] = value;
        }),
      !this.rich,
    );
    if (this.rich) {
      const el = this.element(b.key);
      if (el) el.style.setProperty(prop, value);
    }
  }
  withChips(html: string) {
    const doc = new DOMParser().parseFromString("<body>" + html, "text/html");
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT),
      nodes: Text[] = [];
    while (walker.nextNode()) nodes.push(walker.currentNode as Text);
    for (const node of nodes) {
      const pieces = node.textContent!.split(/(\{[a-zA-Z0-9_]+\})/g);
      if (pieces.length === 1) continue;
      const fragment = doc.createDocumentFragment();
      for (const piece of pieces) {
        if (/^\{[a-zA-Z0-9_]+\}$/.test(piece)) {
          const span = doc.createElement("span");
          span.dataset.variable = piece;
          span.textContent = piece;
          fragment.append(span);
        } else fragment.append(doc.createTextNode(piece));
      }
      node.replaceWith(fragment);
    }
    return doc.body.innerHTML;
  }
  sourceHtml() {
    if (!this.rich) return "";
    const doc = new DOMParser().parseFromString(
      this.rich.getHTML(),
      "text/html",
    );
    doc
      .querySelectorAll("[data-variable]")
      .forEach((el) =>
        el.replaceWith(doc.createTextNode(el.getAttribute("data-variable")!)),
      );
    return doc.body.innerHTML;
  }
  beginEdit() {
    const block = this.block();
    if (
      !block ||
      this.locked(block) ||
      block.type !== "bloc_texte" ||
      this.rich
    )
      return;
    let el = this.element(block.key);
    if (!el) {
      this.pendingEdit = block.key;
      this.status("Préparation du bloc…");
      return;
    }
    const text = block.raw
      ? block.text
      : escape(block.text).replace(/\n/g, "<br>");
    const parsed = new DOMParser().parseFromString(text, "text/html");
    const supported = new Set([
      "P",
      "SPAN",
      "H1",
      "H2",
      "H3",
      "H4",
      "H5",
      "H6",
      "A",
      "TABLE",
      "COLGROUP",
      "COL",
      "THEAD",
      "TBODY",
      "TR",
      "TD",
      "TH",
      "UL",
      "OL",
      "LI",
      "B",
      "I",
      "U",
      "S",
      "STRONG",
      "EM",
      "BR",
      "MARK",
      "CODE",
      "PRE",
      "BLOCKQUOTE",
      "HR",
    ]);
    const attrs = new Set([
      "style",
      "href",
      "target",
      "rel",
      "colspan",
      "rowspan",
      "colwidth",
      "data-colwidth",
      "data-variable",
      "data-page-break",
    ]);
    if (
      Array.from(parsed.body.querySelectorAll("*")).some(
        (e) =>
          (!supported.has(e.tagName) &&
            !(e.tagName === "DIV" && e.hasAttribute("data-page-break"))) ||
          Array.from(e.attributes).some(
            (a) =>
              !attrs.has(a.name) &&
              !(
                a.name === "class" &&
                e.hasAttribute("data-page-break") &&
                a.value === "page-break"
              ),
          ),
      )
    ) {
      this.status(
        "Ce HTML nécessite le mode avancé. Le contenu original est conservé.",
        true,
      );
      return;
    }
    if (this.definition.mode === "flow") {
      const host = this.root.querySelector("#flow-edit") as HTMLElement;
      host.className = "flow-editor";
      host.innerHTML =
        '<div class="row"><strong>Texte source complet · ' +
        escape(block.label) +
        '</strong><button id="end-flow">Terminer l’édition</button></div><div class="flow-source-editor"></div>';
      host
        .querySelector("#end-flow")!
        .addEventListener("click", () => this.endEdit());
      el = host.querySelector(".flow-source-editor") as HTMLElement;
      Object.entries(block.style)
        .filter(([k]) =>
          [
            "font-family",
            "font-size",
            "color",
            "line-height",
            "text-align",
          ].includes(k),
        )
        .forEach(([k, v]) => el!.style.setProperty(k, String(v)));
    }
    el.replaceChildren();
    this.richKey = block.key;
    const content = this.withChips(text);
    this.rich = new Editor({
      element: el,
      extensions: [
        StarterKit.configure({
          undoRedo: false,
          link: {
            openOnClick: false,
            isAllowedUri: (url) =>
              /^(https?:\/\/|mailto:|tel:|#|\{[a-zA-Z0-9_]+\}$)/i.test(url),
          },
        }),
        TextStyle,
        Color,
        FontFamily,
        FontSize,
        TextAlign.configure({ types: ["heading", "paragraph"] }),
        Highlight.configure({ multicolor: true }),
        TableKit.configure({ table: { resizable: true } }),
        RetainedStyles,
        Variable,
        PageBreak,
      ],
      content,
      editorProps: {
        transformPastedHTML: (html) =>
          html
            .replace(/<\/?(?:script|style|iframe|object)[^>]*>/gi, "")
            .replace(/\son\w+\s*=\s*(?:"[^"]*"|'[^']*')/gi, ""),
      },
      onSelectionUpdate: () => this.selectionControls(),
      onUpdate: () => {
        const before = copy(this.definition);
        block.text = this.sourceHtml();
        block.raw = true;
        this.history.push(before, this.definition);
        this.changed(false);
        this.draw();
      },
    });
    this.rich.commands.focus("end");
  }
  endEdit(render = true) {
    if (!this.rich) return;
    this.rich.destroy();
    this.rich = null;
    this.richKey = null;
    this.root.querySelector("#flow-edit")!.replaceChildren();
    this.root.querySelector("#flow-edit")!.className = "";
    if (render) this.render();
  }
  command(cmd: string) {
    const wholeBlock = !this.rich;
    this.beginEdit();
    if (this.rich) {
      const chain = this.rich.chain().focus() as any;
      if (wholeBlock) chain.selectAll();
      if (chain[cmd]) chain[cmd]().run();
    }
  }
  async action(action: string) {
    try {
      if (!this.definition) return;
      if (["page-up", "page-down", "page-delete"].includes(action)) {
        this.endEdit();
        this.mutate(() => {
          const pages = this.definition.pages;
          if (action === "page-delete") {
            if (pages.length === 1)
              throw new Error("Le document doit conserver au moins une page.");
            if (this.blocks().some((b: any) => this.locked(b)))
              throw new Error(
                "Déverrouillez les blocs avant de retirer cette page.",
              );
            pages.splice(this.page, 1);
            this.page = Math.min(this.page, pages.length - 1);
            this.selected = [];
          } else {
            const to = this.page + (action === "page-up" ? -1 : 1);
            if (to >= 0 && to < pages.length) {
              [pages[this.page], pages[to]] = [pages[to], pages[this.page]];
              this.page = to;
            }
          }
        });
        return;
      }
      if (action === "heading" || action === "keep-next") {
        this.beginEdit();
        if (this.rich) {
          if (action === "heading")
            this.rich.chain().focus().toggleHeading({ level: 2 }).run();
          else {
            const old = this.rich.getAttributes("paragraph").legacyStyle || "";
            this.rich
              .chain()
              .focus()
              .updateAttributes("paragraph", {
                legacyStyle:
                  old.replace(/break-after:[^;]+;?/g, "") +
                  "break-after:avoid;",
              })
              .run();
          }
        }
        return;
      }
      if (action === "edit") {
        this.beginEdit();
        return;
      }
      if (action === "undo" || action === "redo") {
        this.endEdit();
        this.definition = this.history[action](this.definition);
        this.selected = [];
        this.changed();
        this.renderControls();
        return;
      }
      if (action === "publish") {
        this.endEdit();
        clearTimeout(this.timer);
        if (this.saving) await this.saving;
        this.busy = true;
        this.status("Enregistrement du modèle…");
        this.state = {
          ...this.state,
          ...(await this.request("publish", {
            definition: this.definition,
            expectedSequence: this.savedSequence,
            baseVersion: this.state.baseVersion,
          })),
        };
        this.definition = this.state.definition;
        this.sequence = 0;
        this.savedSequence = 0;
        this.draftConflict = false;
        this.change = 0;
        this.status("Modèle enregistré");
        this.renderControls();
        this.render();
        return;
      }
      if (action === "pdf") {
        await this.preview();
        return;
      }
      if (action === "export-local") {
        this.endEdit();
        const response = await fetch(this.api.replace("ACTION", "export"), {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": this.csrf,
          },
          body: JSON.stringify({
            definition: this.definition,
            baseVersion: this.state.baseVersion,
          }),
        });
        if (!response.ok) {
          const result = await response.json();
          throw new Error(result.message || "Export impossible.");
        }
        const blob = await response.blob(),
          url = URL.createObjectURL(blob),
          link = document.createElement("a");
        link.href = url;
        link.download = "brouillon-conception.zip";
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
        return;
      }
      if (action === "versions") {
        const versions = await this.request("versions");
        this.panel(
          "Versions enregistrées",
          versions
            .map(
              (v: any) =>
                `<p>Version ${v.version} · ${escape(v.created_at)} <button data-restore="${v.id}">Restaurer dans un brouillon</button></p>`,
            )
            .join(""),
        );
        this.root.querySelectorAll("[data-restore]").forEach((el) =>
          el.addEventListener("click", async () => {
            try {
              this.endEdit();
              clearTimeout(this.timer);
              this.state = {
                ...this.state,
                ...(await this.request("restore", {
                  revision: (el as HTMLElement).dataset.restore,
                  baseVersion: this.state.version,
                  sequence: ++this.sequence,
                })),
              };
              this.definition = this.state.definition;
              this.sequence = this.state.sequence;
              this.savedSequence = this.state.sequence;
              this.draftConflict = false;
              this.change = 0;
              this.selected = [];
              this.closePanel();
              this.renderControls();
              this.render();
              this.status("Version restaurée dans le brouillon");
            } catch (e: any) {
              this.status(e.message, true);
            }
          }),
        );
        return;
      }
      if (action === "image") {
        const b = this.block();
        if (b?.type === "bloc_image" && !this.locked(b))
          (this.root.querySelector("#image-file") as HTMLInputElement).click();
        else this.status("Sélectionnez une image déverrouillée.", true);
        return;
      }
      if (action === "archive") {
        this.panel(
          "Import / export",
          '<p><a href="' +
            this.api.replace("ACTION", "export") +
            '">Exporter le modèle enregistré (ZIP)</a> · <a href="' +
            this.api.replace("ACTION", "export") +
            '?draft=1">Exporter mon brouillon (ZIP)</a></p><p><button data-action="export-local">Exporter mes modifications actuelles</button></p><p>L’import crée un brouillon récupérable. Les versions enregistrées sont conservées.</p><input type="file" id="archive-file" accept=".zip">',
        );
        this.root
          .querySelector("#archive-file")!
          .addEventListener("change", async (e) => {
            try {
              const file = (e.target as HTMLInputElement).files?.[0];
              if (!file) return;
              this.endEdit();
              clearTimeout(this.timer);
              if (this.saving) await this.saving;
              const form = new FormData();
              form.append("archive", file);
              form.append("baseVersion", String(this.state.baseVersion));
              form.append("sequence", String(++this.sequence));
              const result = await this.request("import", undefined, form);
              const before = copy(this.definition);
              this.state = { ...this.state, ...result };
              this.definition = result.definition;
              this.history.push(before, this.definition);
              this.change = 0;
              this.selected = [];
              this.page = 0;
              this.closePanel();
              this.renderControls();
              this.render();
              this.status("Archive importée dans le brouillon");
            } catch (e: any) {
              this.status(e.message, true);
            }
          });
        return;
      }
      if (action === "library") {
        this.library();
        return;
      }
      if (action === "table") {
        this.tablePanel();
        return;
      }
      if (action === "link") {
        this.beginEdit();
        const href = window.prompt(
          "Adresse du lien (https://… ou {url_fe})",
          this.rich?.getAttributes("link").href || "https://",
        );
        if (href !== null && this.rich) {
          if (!href) this.rich.chain().focus().unsetLink().run();
          else if (/^(https?:\/\/|mailto:|tel:|\{\w+\}$)/.test(href))
            this.rich.chain().focus().setLink({ href }).run();
        }
        return;
      }
      if (action === "break") {
        this.beginEdit();
        this.rich?.chain().focus().insertContent({ type: "pageBreak" }).run();
        return;
      }
      if (action === "indent" || action === "outdent") {
        this.beginEdit();
        if (this.rich) {
          const current =
            this.rich.getAttributes("paragraph").legacyStyle || "";
          const n = Math.max(
            0,
            Math.min(
              50,
              parseFloat(current.match(/margin-left:\s*([\d.]+)/)?.[1] || "0") +
                (action === "indent" ? 5 : -5),
            ),
          );
          this.rich
            .chain()
            .focus()
            .updateAttributes("paragraph", {
              legacyStyle:
                current.replace(/margin-left:[^;]+;?/g, "") +
                `margin-left:${n}mm;`,
            })
            .run();
        }
        return;
      }
      this.endEdit();
      this.mutate(() => {
        if (action === "lock")
          this.selected.forEach((k) => {
            const b = this.block(k);
            b.style["--bundle-lock"] = this.locked(b) ? "0" : "1";
          });
        if (action === "delete") {
          this.definition.pages[this.page].blocks = this.blocks().filter(
            (b: any) => !this.selected.includes(b.key) || this.locked(b),
          );
          this.selected = [];
        }
        if (action === "duplicate") {
          const clones = this.selected
            .map((k) => copy(this.block(k)))
            .filter((b) => !this.locked(b));
          clones.forEach((b) => {
            b.key = newKey();
            b.id = null;
            b.label += " (copie)";
            b.style.left = mm(b.style.left) + 3 + "mm";
            b.style.top = mm(b.style.top) + 3 + "mm";
            this.blocks().push(b);
          });
          this.selected = clones.map((b) => b.key);
        }
        if (action === "front" || action === "back") {
          const levels = this.blocks().map((b: any) =>
            Number(b.style["z-index"] || 0),
          );
          this.selected.forEach((k) => {
            const b = this.block(k);
            if (!this.locked(b))
              b.style["z-index"] = String(
                action === "front"
                  ? Math.max(...levels) + 1
                  : Math.min(...levels) - 1,
              );
          });
        }
        if (action === "background" && this.block()?.type === "bloc_image") {
          const [w, h] = this.dimensions();
          Object.assign(this.block().style, {
            left: "0mm",
            top: "0mm",
            width: w + "mm",
            height: h + "mm",
            "z-index": "0",
            "--bundle-lock": "1",
          });
        }
      });
    } catch (e: any) {
      this.status(e.message, true);
    } finally {
      this.busy = false;
    }
  }
  add(kind: string, html?: string, label?: string) {
    this.endEdit();
    if (kind === "image") {
      this.selected = [];
      (this.root.querySelector("#image-file") as HTMLInputElement).click();
      return;
    }
    this.mutate(() => {
      if (kind === "page") {
        this.definition.pages.push({
          key: newKey(),
          id: null,
          label: "Page " + (this.definition.pages.length + 1),
          conditions: [],
          blocks: [],
        });
        this.page = this.definition.pages.length - 1;
        this.selected = [];
        return;
      }
      const key = newKey();
      const b = {
        key,
        id: null,
        type: kind === "shape" ? "formes_predefinies" : "bloc_texte",
        label:
          label || (kind === "table" ? "Nouveau tableau" : "Nouveau texte"),
        order: this.blocks().length + 1,
        raw: true,
        text:
          html ||
          (kind === "table"
            ? '<table style="width:100%;border-collapse:collapse"><tbody><tr><th style="border:1px solid #777">Libellé</th><th style="border:1px solid #777">Valeur</th></tr><tr><td style="border:1px solid #777">Texte</td><td style="border:1px solid #777">Texte</td></tr></tbody></table>'
            : "<p>Écrivez votre texte ici…</p>"),
        style: {
          position: "absolute",
          left: "20mm",
          top: "100mm",
          width: "170mm",
          height: "auto",
          "font-family": "Arial, sans-serif",
          "font-size": "10pt",
          "line-height": "1.25",
          "z-index": "2",
        },
        styles: [],
        conditions: [],
      };
      if (kind === "shape") {
        Object.assign(b, { shape: "rectangle", text: "", label: "Rectangle" });
        Object.assign(b.style, {
          "background-color": "#e7edf5",
          border: "0.3mm solid #53657e",
          height: "20mm",
          width: "50mm",
        });
      }
      this.blocks().push(b);
      this.selected = [key];
    });
  }
  async upload(file: File) {
    try {
      this.endEdit();
      this.status("Chargement de l’image…");
      const form = new FormData();
      form.append("image", file);
      const asset = await this.request("image", undefined, form);
      this.assets.set(asset.id, asset);
      this.mutate(() => {
        const b = this.block();
        if (b?.type === "bloc_image") {
          if (this.locked(b)) throw new Error("Image verrouillée.");
          b.asset = asset;
        } else {
          const key = newKey();
          const ratio =
            asset.width && asset.height ? asset.height / asset.width : 0.6;
          this.blocks().push({
            key,
            id: null,
            type: "bloc_image",
            label: file.name,
            style: {
              position: "absolute",
              left: "20mm",
              top: "20mm",
              width: ratio > 2 ? "auto" : "50mm",
              height: ratio > 2 ? "100mm" : "auto",
              "z-index": "2",
            },
            styles: [],
            conditions: [],
            asset,
            fit: "contain",
            crop: "50% 50%",
          });
          this.selected = [key];
        }
      });
    } catch (e: any) {
      this.status(e.message, true);
    }
  }
  align(command: string) {
    this.endEdit();
    if (this.definition.mode === "flow") {
      this.status(
        "Les alignements libres concernent les pages en blocs libres.",
        true,
      );
      return;
    }
    const keys = this.selected.filter((k) => !this.locked(this.block(k)));
    let target: any;
    const mode = (this.root.querySelector("#align-target") as HTMLSelectElement)
      .value;
    const [w, h] = this.dimensions();
    if (mode === "page") target = { x: 0, y: 0, w, h };
    if (mode === "margins") {
      const m = this.definition.pageSpec.margins;
      target = {
        x: m.left,
        y: m.top,
        w: w - m.left - m.right,
        h: h - m.top - m.bottom,
      };
    }
    if (!target && this.selected.length)
      target = this.box(this.selected.at(-1)!);
    const boxes = align(
      keys.map((k) => this.box(k)),
      command,
      target,
    );
    this.mutate(() =>
      boxes.forEach((box) =>
        Object.assign(this.block(box.key).style, {
          left: box.x.toFixed(2) + "mm",
          top: box.y.toFixed(2) + "mm",
        }),
      ),
    );
  }
  diagnostics() {
    if (!this.definition) return;
    const [w, h] = this.dimensions();
    const warnings: string[] = this.renderError ? [this.renderError] : [];
    for (const b of this.blocks()) {
      const el = this.element(b.key);
      if (this.definition.mode === "free" && el) {
        const box = this.box(b.key);
        if (
          box.x + box.w > w + 0.2 ||
          box.y + box.h > h + 0.2 ||
          el.scrollHeight > el.clientHeight + 2
        )
          warnings.push(`${b.label} : débordement à vérifier.`);
      }
      if (
        b.asset?.width &&
        !b.asset.id.endsWith(".svg") &&
        (el ? (el.offsetWidth * 25.4) / 96 : mm(b.style.width)) > 0 &&
        b.asset.width /
          ((el ? (el.offsetWidth * 25.4) / 96 : mm(b.style.width)) / 25.4) <
          150
      )
        warnings.push(`${b.label} : résolution inférieure à 150 dpi.`);
    }
    this.root.querySelector("#diagnostics")!.innerHTML = warnings
      .map((w) => `<div class="warning">${escape(w)}</div>`)
      .join("");
  }
  panel(title: string, content: string) {
    this.closePanel();
    const p = document.createElement("section");
    p.className = "panel";
    p.setAttribute("role", "dialog");
    p.innerHTML = `<div class="row"><h2>${escape(title)}</h2><button id="close-panel">Fermer</button></div>${content}`;
    this.root.append(p);
    p.querySelector("#close-panel")!.addEventListener("click", () =>
      this.closePanel(),
    );
  }
  closePanel() {
    this.root.querySelector(".panel")?.remove();
  }
  library() {
    const presets = [
      [
        "Adresse",
        "<p>Nom du destinataire<br>Adresse<br>Code postal · Ville</p>",
      ],
      ["Titre", "<h1>Titre du document</h1>"],
      [
        "Informations pratiques",
        "<h3>À retenir</h3><p>Date limite : à compléter</p>",
      ],
      [
        "Identifiants",
        "<p><strong>Votre identifiant :</strong> insérez un champ</p><p><strong>Votre mot de passe :</strong> insérez un champ</p>",
      ],
      [
        "Avertissement",
        '<p style="border:1px solid #d22336;padding:3mm"><strong>IMPORTANT</strong><br>Votre information ici.</p>',
      ],
      ["Signature", '<p style="text-align:right">Le service client</p>'],
      ["Pied de page", "<p>Votre organisme · adresse · coordonnées</p>"],
    ];
    presets.push(
      ["Numérotation des pages", "<p>Page {page_number} / {page_total}</p>"],
      ...(this.state.presets || []).map((p: any) => [p.label, p.html]),
    );
    const images = new Map(this.assets);
    for (const page of this.definition.pages)
      for (const b of page.blocks) if (b.asset) images.set(b.asset.id, b.asset);
    this.panel(
      "Bibliothèque de blocs",
      presets
        .map(
          ([name], i) => `<button data-preset="${i}">${escape(name)}</button>`,
        )
        .join("") +
        '<p>Les champs de personnalisation de l’application peuvent être insérés dans ces blocs.</p><h3>Images de la conception</h3><div class="gallery">' +
        Array.from(images.values())
          .map(
            (a: any) =>
              `<img alt="Image de la conception" data-image-id="${a.id}" src="${this.api.replace("ACTION", "asset")}?asset=${a.id}">`,
          )
          .join("") +
        "</div>",
    );
    this.root.querySelectorAll("[data-preset]").forEach((el) =>
      el.addEventListener("click", () => {
        const [name, html] =
          presets[Number((el as HTMLElement).dataset.preset)];
        this.closePanel();
        this.add("text", html, name);
      }),
    );
    this.root.querySelectorAll("[data-image-id]").forEach((el) =>
      el.addEventListener("click", () => {
        const asset = images.get((el as HTMLElement).dataset.imageId);
        this.closePanel();
        this.selected = [];
        this.add("text");
        this.mutate(() => {
          const b = this.block();
          b.type = "bloc_image";
          b.label = "Image";
          b.asset = asset;
          b.text = "";
          b.fit = "contain";
          b.style.width = "50mm";
          b.style.height = "30mm";
        });
      }),
    );
  }
  tablePanel() {
    this.beginEdit();
    if (!this.rich) return;
    const commands = [
      ["addRowBefore", "Ligne avant"],
      ["addRowAfter", "Ligne après"],
      ["deleteRow", "Supprimer la ligne"],
      ["addColumnBefore", "Colonne avant"],
      ["addColumnAfter", "Colonne après"],
      ["deleteColumn", "Supprimer la colonne"],
      ["mergeCells", "Fusionner"],
      ["splitCell", "Séparer"],
      ["toggleHeaderRow", "Ligne d’en-tête"],
    ];
    this.panel(
      "Tableau",
      commands
        .map(([v, l]) => `<button data-table-command="${v}">${l}</button>`)
        .join("") +
        '<p><label>Fond de cellule <input type="color" id="cell-bg" value="#ffffff"></label> <label>Bordure <input type="color" id="cell-border" value="#555555"></label><label>Alignement vertical <select id="cell-align"><option value="top">Haut</option><option value="middle">Milieu</option><option value="bottom">Bas</option></select></label> <button id="cell-style">Appliquer aux cellules sélectionnées</button></p>',
    );
    this.root.querySelectorAll("[data-table-command]").forEach((el) => {
      el.addEventListener("mousedown", (e) => e.preventDefault());
      el.addEventListener("click", () => {
        const cmd = (el as HTMLElement).dataset.tableCommand!;
        (this.rich!.chain().focus() as any)[cmd]().run();
      });
    });
    this.root.querySelector("#cell-style")!.addEventListener("click", () => {
      const bg = (this.root.querySelector("#cell-bg") as HTMLInputElement)
          .value,
        border = (this.root.querySelector("#cell-border") as HTMLInputElement)
          .value;
      this.rich
        ?.chain()
        .focus()
        .setCellAttribute(
          "legacyStyle",
          `background-color:${bg};border:1px solid ${border};vertical-align:${(this.root.querySelector("#cell-align") as HTMLSelectElement).value};`,
        )
        .run();
    });
  }
  async preview() {
    this.endEdit();
    const generation = ++this.pdfGeneration;
    const definition = copy(this.definition);
    this.status("Génération du PDF Gotenberg…");
    const result = await this.request("preview", {
      definition,
      baseVersion: this.state.baseVersion,
    });
    if (
      generation !== this.pdfGeneration ||
      JSON.stringify(definition) !== JSON.stringify(this.definition)
    ) {
      this.status("Le document a changé : relancez la vérification PDF.");
      return;
    }
    this.status("PDF vérifié");
    this.root.querySelector("#pdf-status")!.textContent =
      `PDF à jour · ${result.logicalPages} page(s) de lecture · ${result.physicalPages} face(s) d’impression`;
    this.openPdf(result);
  }
  async openPdf(result: any) {
    this.root.querySelector(".pdf-panel")?.remove();
    const panel = document.createElement("section");
    panel.className = "pdf-panel";
    panel.setAttribute("role", "dialog");
    panel.innerHTML = `<div class="pdf-toolbar"><strong>Aperçu PDF Gotenberg</strong><span class="pdf-freshness">PDF à jour</span><button data-pdf-view="reading">Lecture</button><button data-pdf-view="printing">Feuilles d’impression</button><label>Zoom <input type="number" id="pdf-zoom" value="100" min="25" max="200"></label><a id="pdf-download" download>Télécharger ce PDF</a><button id="pdf-close">Fermer</button></div><div class="pdf-pages"></div>`;
    this.root.append(panel);
    let view = "reading";
    let sequence = 0;
    const display = async () => {
      const own = ++sequence;
      const url = view === "reading" ? result.readingUrl : result.printingUrl;
      (panel.querySelector("#pdf-download") as HTMLAnchorElement).href =
        url + "&download=1";
      const pdf = await pdfjs.getDocument({ url, withCredentials: true })
        .promise;
      if (own !== sequence) return;
      const pages = panel.querySelector(".pdf-pages")!;
      pages.innerHTML = "";
      for (let p = 1; p <= pdf.numPages; p++) {
        if (own !== sequence) return;
        const page = await pdf.getPage(p);
        const viewport = page.getViewport({
          scale:
            Number(
              (panel.querySelector("#pdf-zoom") as HTMLInputElement).value,
            ) / 100,
        });
        const canvas = document.createElement("canvas");
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        canvas.setAttribute("aria-label", `Page PDF ${p}`);
        pages.append(canvas);
        await page.render({ canvas, viewport }).promise;
      }
    };
    panel.querySelectorAll("[data-pdf-view]").forEach((el) =>
      el.addEventListener("click", () => {
        view = (el as HTMLElement).dataset.pdfView!;
        display().catch((e) => this.status(e.message, true));
      }),
    );
    panel
      .querySelector("#pdf-zoom")!
      .addEventListener("change", () => display());
    panel.querySelector("#pdf-close")!.addEventListener("click", () => {
      sequence++;
      panel.remove();
    });
    await display();
  }
  key(e: KeyboardEvent) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
      e.preventDefault();
      e.stopImmediatePropagation();
      this.action("publish");
      return;
    }
    if ((e.ctrlKey || e.metaKey) && ["z", "y"].includes(e.key.toLowerCase())) {
      e.preventDefault();
      e.stopImmediatePropagation();
      this.action(e.key.toLowerCase() === "y" || e.shiftKey ? "redo" : "undo");
      return;
    }
    if (e.key === "Escape") {
      this.deselect();
      return;
    }
    const target = e.target as HTMLElement;
    if (target.closest("input,textarea,select") || target.isContentEditable)
      return;
    if (e.key === "Enter") {
      this.beginEdit();
      return;
    }
    const delta = {
      ArrowLeft: [-1, 0],
      ArrowRight: [1, 0],
      ArrowUp: [0, -1],
      ArrowDown: [0, 1],
    }[e.key];
    if (delta && this.selected.length && this.definition.mode === "free") {
      e.preventDefault();
      this.mutate(() =>
        this.selected.forEach((k) => {
          const b = this.block(k);
          if (!this.locked(b)) {
            b.style.left =
              Math.max(0, mm(b.style.left) + delta[0] * (e.shiftKey ? 5 : 1)) +
              "mm";
            b.style.top =
              Math.max(0, mm(b.style.top) + delta[1] * (e.shiftKey ? 5 : 1)) +
              "mm";
          }
        }),
      );
    }
  }
}
const root = document.getElementById("document-editor");
if (root) new DocumentEditor(root);
