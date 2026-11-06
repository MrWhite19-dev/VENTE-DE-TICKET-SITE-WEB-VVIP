"use strict";

const toast = document.getElementById("toast");
const whatsappContact = document.getElementById("whatsapp-contact");
const whatsappNumber = (whatsappContact?.textContent || "").replace(/\D/g, "");
let toastTimeout;

if (whatsappContact && /^\d{10,15}$/.test(whatsappNumber)) {
  whatsappContact.href = "https://wa.me/" + whatsappNumber;
}

function showToast(message) {
  if (!toast) return;
  toast.textContent = message;
  toast.classList.add("visible");
  window.clearTimeout(toastTimeout);
  toastTimeout = window.setTimeout(() => toast.classList.remove("visible"), 6000);
}

function formatFCFA(amount) {
  return new Intl.NumberFormat("fr-FR").format(amount) + " FCFA";
}

function updateEstimate(card) {
  const price = card.querySelector("[data-ticket-price]");
  const quantity = card.querySelector('select[name="quantity"]');
  const total = card.querySelector("[data-total-estimate]");
  if (!price || !quantity || !total) return;
  const unitPrice = Number(price.textContent.replace(/\D/g, ""));
  total.textContent = formatFCFA(unitPrice * Number(quantity.value || 1));
}

let inventoryRequestId = 0;

document.querySelectorAll(".ticket-form").forEach((form) => {
  const card = form.closest(".ticket-card");
  const quantitySelect = form.querySelector('select[name="quantity"]');
  if (card && quantitySelect) {
    quantitySelect.addEventListener("change", () => updateEstimate(card));
    updateEstimate(card);
  }
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;

    const submitButton = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const payload = {
      csrf_token: formData.get("csrf_token"),
      ticket_type_id: Number(form.dataset.ticketType),
      name: String(formData.get("name") || "").trim(),
      email: String(formData.get("email") || "").trim(),
      phone: String(formData.get("phone") || "").trim(),
      quantity: Number(formData.get("quantity")),
    };

    if (submitButton) submitButton.disabled = true;
    try {
      const response = await fetch("api/reservation.php", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(payload),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || "La demande n’a pas pu être envoyée.");

      showToast(
        result.message + " Référence : " + result.reference + " — " +
        result.quantity + " pass " + result.ticket + ", total " +
        formatFCFA(result.total) + "."
      );
      form.reset();
      await refreshInventory();
    } catch (error) {
      await refreshInventory();
      showToast(error instanceof Error ? error.message : "Erreur inattendue lors de l’envoi.");
    } finally {
      if (submitButton) {
        submitButton.disabled = Boolean(submitButton.dataset.soldOut || submitButton.dataset.inventoryUnavailable);
      }
    }
  });
});

async function refreshInventory(sessionId = document.body.dataset.sessionId) {
  if (!sessionId) return;
  const requestId = ++inventoryRequestId;
  document.body.dataset.sessionId = String(sessionId);

  document.querySelectorAll(".ticket-form button").forEach((button) => {
    button.disabled = true;
  });
  try {
    const response = await fetch("api/inventory.php?session_id=" + encodeURIComponent(sessionId), {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    const data = await response.json();
    if (requestId !== inventoryRequestId) return;
    if (!response.ok) throw new Error(data.error || "Séance indisponible.");
    const tickets = data.tickets;
    document.body.dataset.sessionId = String(data.session.id);
    const summary = document.getElementById("selected-session-summary");
    if (summary) {
      summary.textContent = data.session.titre + " — " + data.session.date_seance +
        " à " + String(data.session.heure_debut).slice(0, 5) + " • " + data.session.salle;
    }

    tickets.forEach((ticket) => {
      const key = String(ticket.nom).toLowerCase();
      const remaining = Number(ticket.places_restantes);
      const stock = document.getElementById(key + "-stock");
      const sold = document.getElementById(key + "-sold");
      const bar = document.getElementById(key + "-stock-bar");
      const card = document.getElementById(key);
      if (stock) stock.textContent = String(remaining);
      if (sold) sold.textContent = String(ticket.tickets_confirmes);
      if (bar) {
        const capacity = Number(ticket.capacite);
        bar.style.width = (capacity > 0 ? (remaining / capacity) * 100 : 0) + "%";
      }

      if (card) {
        card.style.display = ticket.statut === "actif" ? "" : "none";
        const offer = card.querySelector(".offer-label");
        const price = card.querySelector("[data-ticket-price]");
        const select = card.querySelector('select[name="quantity"]');
        const button = card.querySelector('button[type="submit"]');
        const form = card.querySelector(".ticket-form");
        if (form) form.dataset.ticketType = String(ticket.id);
        if (price) price.textContent = new Intl.NumberFormat("fr-FR").format(ticket.prix_ticket);
        if (offer) offer.textContent = remaining > 0 ? "PLACES DISPONIBLES : " + remaining : "COMPLET";
        if (select && button) {
          delete button.dataset.inventoryUnavailable;
          const previousQuantity = Number(select.value) || 1;
          select.replaceChildren();
          for (let quantity = 1; quantity <= Math.min(10, remaining); quantity++) {
            const option = document.createElement("option");
            option.value = String(quantity);
            option.textContent = quantity + " pass";
            select.append(option);
          }
          select.value = String(Math.min(previousQuantity, Math.max(remaining, 1)));
          select.disabled = remaining < 1;
          button.disabled = remaining < 1;
          if (remaining < 1) button.dataset.soldOut = "true";
          else delete button.dataset.soldOut;
          updateEstimate(card);
        }
      }
    });
    document.querySelectorAll(".ticket-card").forEach((card) => {
      const key = card.id.toUpperCase();
      const available = tickets.some((ticket) =>
        String(ticket.nom).toUpperCase() === key && ticket.statut === "actif"
      );
      card.style.display = available ? "" : "none";
    });
  } catch (error) {
    if (requestId !== inventoryRequestId) return;
    console.error(error);
    document.querySelectorAll(".ticket-form button").forEach((button) => {
      button.disabled = true;
      button.dataset.inventoryUnavailable = "true";
    });
    showToast(error instanceof Error ? error.message : "Impossible d’actualiser les séances.");
  }
}

refreshInventory();
window.setInterval(refreshInventory, 30000);

const filmSelect = document.getElementById("film-select");
const sessionSelect = document.getElementById("session-select");
if (filmSelect && sessionSelect) {
  function filterSessions() {
    let firstVisible = null;
    Array.from(sessionSelect.options).forEach((option) => {
      const matches = option.dataset.film === filmSelect.value;
      option.hidden = !matches;
      if (matches && firstVisible === null) firstVisible = option;
    });
    if (firstVisible) {
      sessionSelect.value = firstVisible.value;
      refreshInventory(firstVisible.value);
    }
  }
  filmSelect.addEventListener("change", filterSessions);
  sessionSelect.addEventListener("change", () => refreshInventory(sessionSelect.value));
  document.querySelectorAll("[data-film-choice]").forEach((card) => {
    card.addEventListener("click", () => {
      filmSelect.value = card.dataset.filmChoice;
      filterSessions();
    });
  });
}

document.querySelectorAll('a[href^="#"]').forEach((link) => {
  link.addEventListener("click", () => {
    const target = document.querySelector(link.getAttribute("href"));
    if (target) target.setAttribute("tabindex", "-1");
  });
});

document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("cinemaModal");
  const video = document.getElementById("cinemaTrailerVideo");
  const frame = document.getElementById("cinemaTrailerFrame");
  const playButton = document.getElementById("mainTrailerBtn");
  const closeButton = document.getElementById("closeCinemaModal");

  if (!modal || (!video && !frame) || !playButton || !closeButton) return;

  function closeTrailer() {
    modal.classList.remove("open");
    modal.setAttribute("aria-hidden", "true");
    if (video) {
      video.pause();
      video.currentTime = 0;
    }
    if (frame) {
      frame.setAttribute("src", "about:blank");
    }
    document.body.style.overflow = "";
    playButton.focus();
  }

  playButton.addEventListener("click", () => {
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    closeButton.focus();
    if (video) {
      video.muted = false;
      video.volume = 1;
      video.play().catch(() => showToast("La lecture automatique est bloquée. Appuyez sur lecture pour regarder la bande-annonce avec le son."));
    }
    if (frame) {
      const source = frame.dataset.source || "about:blank";
      frame.setAttribute("src", source === "about:blank" ? source : source + "?autoplay=1&mute=0");
    }
  });

  closeButton.addEventListener("click", closeTrailer);
  modal.addEventListener("click", (event) => {
    if (event.target === modal) closeTrailer();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("open")) closeTrailer();
  });
});
