const appState = window.CARESYNC || {};

const loginCard = document.getElementById("loginCard");
const createAccountCard = document.getElementById("createAccountCard");
const showCreateAccountBtn = document.getElementById("showCreateAccountBtn");
const backToLoginBtn = document.getElementById("backToLoginBtn");
const roleSwitch = document.getElementById("roleSwitch");
const createRoleGrid = document.getElementById("createRoleGrid");
const loginRoleInput = document.getElementById("loginRoleInput");
const createRoleInput = document.getElementById("createRoleInput");
const doctorExtraFields = document.getElementById("doctorExtraFields");
const createAgeInput = document.getElementById("createAge");
const qualificationInput = document.getElementById("qualification");
const loginTitle = document.getElementById("loginTitle");
const loginSubtitle = document.getElementById("loginSubtitle");
const emailInput = document.getElementById("email");

const chatCard = document.getElementById("chatCard");
const chatThread = document.getElementById("chatThread");
const chatForm = document.getElementById("chatForm");
const chatMessage = document.getElementById("chatMessage");
const chatMessageStatus = document.getElementById("chatMessageStatus");
const chatNotificationBadge = document.getElementById("chatNotificationBadge");
const markChatReadBtn = document.getElementById("markChatReadBtn");

function setLoginRole(role) {
  if (!loginRoleInput) {
    return;
  }

  loginRoleInput.value = role;

  roleSwitch?.querySelectorAll("[data-role-switch]").forEach((button) => {
    button.classList.toggle("active", button.dataset.roleSwitch === role);
  });

  if (loginTitle && loginSubtitle && emailInput) {
    if (role === "doctor") {
      loginTitle.textContent = "Doctor Login";
      loginSubtitle.textContent = "Doctors can sign in to manage patient health notes.";
      emailInput.placeholder = "doctor@example.com";
      showCreateAccountBtn?.classList.remove("hidden");
    } else if (role === "admin") {
      loginTitle.textContent = "Admin Login";
      loginSubtitle.textContent = "Administrators can review doctor and patient records.";
      emailInput.placeholder = "admin@example.com";
      showCreateAccountBtn?.classList.add("hidden");
    } else {
      loginTitle.textContent = "Caretaker Login";
      loginSubtitle.textContent = "Caretakers can sign in to manage medicines and patient routines.";
      emailInput.placeholder = "caretaker@example.com";
      showCreateAccountBtn?.classList.remove("hidden");
    }
  }
}

function setCreateRole(role) {
  if (!createRoleInput) {
    return;
  }

  createRoleInput.value = role;
  createRoleGrid?.querySelectorAll("[data-create-role]").forEach((button) => {
    button.classList.toggle("active", button.dataset.createRole === role);
  });

  if (doctorExtraFields && createAgeInput && qualificationInput) {
    const isDoctor = role === "doctor";
    doctorExtraFields.classList.toggle("hidden", !isDoctor);
    createAgeInput.required = isDoctor;
    qualificationInput.required = isDoctor;
  }
}

showCreateAccountBtn?.addEventListener("click", () => {
  loginCard?.classList.add("hidden");
  createAccountCard?.classList.remove("hidden");
});

backToLoginBtn?.addEventListener("click", () => {
  createAccountCard?.classList.add("hidden");
  loginCard?.classList.remove("hidden");
});

roleSwitch?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-role-switch]");
  if (!button) {
    return;
  }

  setLoginRole(button.dataset.roleSwitch);
});

createRoleGrid?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-create-role]");
  if (!button) {
    return;
  }

  setCreateRole(button.dataset.createRole);
});

setLoginRole(appState.role || "doctor");
setCreateRole(appState.createRole || "doctor");

document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-admin-toggle]");
  if (!button) {
    return;
  }

  event.preventDefault();
  event.stopPropagation();

  const targetId = button.getAttribute("data-admin-toggle");
  if (!targetId) {
    return;
  }

  const detail = document.getElementById(targetId);
  if (!detail) {
    return;
  }

  detail.classList.remove("hidden");
  button.classList.add("hidden");
});

document.querySelectorAll(".admin-directory-item").forEach((item) => {
  item.addEventListener("toggle", () => {
    if (!item.open) {
      return;
    }

    const container = item.closest(".admin-directory-list");
    if (!container) {
      return;
    }

    container.querySelectorAll(".admin-directory-item[open]").forEach((openItem) => {
      if (openItem !== item) {
        openItem.open = false;
      }
    });
  });
});

function escapeHtml(value) {
  const element = document.createElement("div");
  element.textContent = value;
  return element.innerHTML;
}

function formatDateTime(value) {
  const date = new Date(value.replace(" ", "T"));
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString("en-IN", {
    day: "numeric",
    month: "short",
    hour: "numeric",
    minute: "2-digit",
  });
}

function updateUnreadBadge(unread) {
  if (!chatNotificationBadge) {
    return;
  }

  chatNotificationBadge.textContent = `${unread} unread`;
  if (markChatReadBtn) {
    markChatReadBtn.classList.toggle("hidden", unread === 0);
  }
}

async function fetchChat() {
  if (!appState.userLoggedIn || !appState.patientLinked || !chatThread) {
    return;
  }

  const response = await fetch("api.php?action=chat_fetch", {
    headers: {
      "X-Requested-With": "XMLHttpRequest",
    },
  });
  const result = await response.json();

  if (!result.ok) {
    chatMessageStatus.textContent = result.message || "Unable to load chat.";
    return;
  }

  updateUnreadBadge(result.unread || 0);

  if (!Array.isArray(result.messages) || result.messages.length === 0) {
    chatThread.className = "chat-thread empty-state";
    chatThread.textContent = "No messages yet. Start the conversation here.";
    return;
  }

  chatThread.className = "chat-thread";
  chatThread.innerHTML = result.messages
    .map((message) => {
      const ownMessage = Number(message.sender_id) === Number(result.partnerId) ? "chat-bubble-other" : "chat-bubble-own";
      const senderName = Number(message.sender_id) === Number(result.partnerId) ? message.sender_name : "You";

      return `
        <article class="chat-bubble ${ownMessage}">
          <div class="chat-bubble-meta">
            <strong>${escapeHtml(senderName)}</strong>
            <span>${escapeHtml(formatDateTime(message.created_at))}</span>
          </div>
          <p>${escapeHtml(message.message_text)}</p>
        </article>
      `;
    })
    .join("");

  chatThread.scrollTop = chatThread.scrollHeight;
}

chatForm?.addEventListener("submit", async (event) => {
  event.preventDefault();

  const message = chatMessage?.value.trim();
  if (!message) {
    chatMessageStatus.textContent = "Please type a message before sending.";
    return;
  }

  chatMessageStatus.textContent = "Sending...";

  const response = await fetch("api.php?action=chat_send", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
    body: JSON.stringify({ message }),
  });
  const result = await response.json();

  if (!result.ok) {
    chatMessageStatus.textContent = result.message || "Message could not be sent.";
    return;
  }

  chatForm.reset();
  chatMessageStatus.textContent = "Message sent.";
  await fetchChat();
});

markChatReadBtn?.addEventListener("click", async () => {
  const response = await fetch("api.php?action=chat_mark_read", {
    method: "POST",
    headers: {
      "X-Requested-With": "XMLHttpRequest",
    },
  });
  const result = await response.json();

  if (result.ok) {
    updateUnreadBadge(result.unread || 0);
    chatMessageStatus.textContent = "Notifications cleared.";
  }
});

if (appState.userLoggedIn && appState.patientLinked && chatCard?.dataset.hasChat === "1") {
  fetchChat();
  window.setInterval(fetchChat, 8000);
}
