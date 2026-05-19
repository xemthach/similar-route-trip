(function () {
	"use strict";

	var previewTimer = null;

	function getSrtAdminConfig() {
		return window.SRTAdmin || {};
	}

	function getSelectedOptionText(select) {
		if (!select || select.selectedIndex < 0) {
			return "";
		}
		var option = select.options[select.selectedIndex];
		return option ? (option.getAttribute("data-route-label") || option.textContent || "") : "";
	}

	function updateContentPreviewLabels() {
		var routeSelect = document.getElementById("srt-route-select");
		var topicSelect = document.getElementById("srt-topic-select");
		var lengthSelect = document.getElementById("srt-length-select");
		var previewRoute = document.getElementById("srt-preview-route");
		var previewTopic = document.getElementById("srt-preview-topic");
		var previewLength = document.getElementById("srt-preview-length");

		if (routeSelect && previewRoute) {
			previewRoute.textContent = getSelectedOptionText(routeSelect);
		}
		if (topicSelect && previewTopic) {
			var topicOption = topicSelect.options[topicSelect.selectedIndex];
			previewTopic.textContent = topicOption ? topicOption.textContent : "";
		}
		if (lengthSelect && previewLength) {
			previewLength.textContent = lengthSelect.value || "";
		}
	}

	function getFieldValue(id) {
		var field = document.getElementById(id);
		if (!field) {
			return "";
		}
		return field.value || "";
	}

	function setPreviewState(message, isError) {
		var status = document.getElementById("srt-preview-status");
		if (!status) {
			return;
		}
		status.textContent = message;
		status.className = isError ? "srt-preview-status srt-preview-status-error" : "srt-preview-status";
	}

	function setPreviewContent(data) {
		var quality = document.getElementById("srt-preview-quality");
		var similarity = document.getElementById("srt-preview-similarity");
		var seoTitle = document.getElementById("srt-preview-seo-title");
		var metaDescription = document.getElementById("srt-preview-meta-description");
		var warnings = document.getElementById("srt-preview-warnings");
		var content = document.getElementById("srt-preview-content");
		var previewQuality = data && data.preview_quality ? data.preview_quality : null;
		var previewSimilarity = data && data.preview_similarity ? data.preview_similarity : null;
		var previewWarnings = data && data.preview_warnings ? data.preview_warnings : [];

		if (quality) {
			quality.textContent = previewQuality && typeof previewQuality.score !== "undefined" ? String(previewQuality.score) + "/100" : "-";
		}
		if (similarity) {
			if (previewSimilarity && typeof previewSimilarity.score !== "undefined") {
				var matched = previewSimilarity.matched_post_id ? (" post #" + previewSimilarity.matched_post_id) : "";
				similarity.textContent = String(previewSimilarity.score) + matched;
			} else {
				similarity.textContent = "-";
			}
		}
		if (seoTitle) {
			seoTitle.textContent = data && data.structured && data.structured.seo_title ? data.structured.seo_title : (data && data.title ? data.title : "-");
		}
		if (metaDescription) {
			metaDescription.textContent = data && data.structured && data.structured.meta_description ? data.structured.meta_description : "-";
		}
		if (warnings) {
			if (previewWarnings && previewWarnings.length) {
				warnings.innerHTML = "<strong>Warnings</strong><ul>" + previewWarnings.map(function (item) {
					return "<li>" + String(item).replace(/[&<>]/g, function (ch) {
						return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" })[ch];
					}) + "</li>";
				}).join("") + "</ul>";
			} else {
				warnings.innerHTML = "";
			}
		}
		if (content) {
			content.innerHTML = data && data.content ? data.content : "<p>-</p>";
		}
	}

	function fetchContentPreview() {
		var routeSelect = document.getElementById("srt-route-select");
		var config = getSrtAdminConfig();
		if (!routeSelect || !config.restUrl || !config.previewEndpoint) {
			return;
		}

		var routeId = routeSelect.value;
		if (!routeId) {
			setPreviewState("Chua co route de preview", true);
			setPreviewContent({});
			return;
		}

		var payload = {
			route_id: routeId,
			template: getFieldValue("srt-template-select") || "route_landing",
			topic: getFieldValue("srt-topic-select") || "route_landing",
			content_length: getFieldValue("srt-length-select") || "standard",
			min_words: getFieldValue("srt-min-words"),
			max_words: getFieldValue("srt-max-words"),
			primary_keyword: getFieldValue("srt-primary-keyword"),
			secondary_keywords: getFieldValue("srt-secondary-keywords"),
			use_ai: document.querySelector('input[name="use_ai"]') && document.querySelector('input[name="use_ai"]').checked ? 1 : 0
		};

		setPreviewState("Dang tai preview...", false);
		fetch(config.restUrl.replace(/\/$/, "") + "/" + config.previewEndpoint.replace(/^\//, ""), {
			method: "POST",
			credentials: "same-origin",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": config.restNonce || ""
			},
			body: JSON.stringify(payload)
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error("HTTP " + response.status);
				}
				return response.json();
			})
			.then(function (data) {
				setPreviewState("Preview da cap nhat", false);
				setPreviewContent(data || {});
			})
			.catch(function (error) {
				setPreviewState("Khong tai duoc preview: " + error.message, true);
				setPreviewContent({});
			});
	}

	function schedulePreviewRefresh() {
		if (previewTimer) {
			window.clearTimeout(previewTimer);
		}
		previewTimer = window.setTimeout(function () {
			fetchContentPreview();
		}, 250);
	}

	document.querySelectorAll('form input[name="action"][value="srt_unlink_post"]').forEach(function (el) {
		var form = el.closest("form");
		if (!form) {
			return;
		}
		form.addEventListener("submit", function (ev) {
			if (!window.confirm("Unlink this post from route? The post will not be deleted.")) {
				ev.preventDefault();
			}
		});
	});

	document.querySelectorAll(".srt-toggle-provider-editor").forEach(function (button) {
		button.addEventListener("click", function () {
			var target = document.querySelector(button.getAttribute("data-target"));
			if (!target) {
				return;
			}
			target.hidden = !target.hidden;
		});
	});

	document.querySelectorAll('form input[name="action"][value="srt_delete_ai_key"]').forEach(function (el) {
		var form = el.closest("form");
		if (!form) {
			return;
		}
		form.addEventListener("submit", function (ev) {
			if (!window.confirm("Delete this provider key?")) {
				ev.preventDefault();
			}
		});
	});

	["srt-route-select", "srt-topic-select", "srt-length-select", "srt-template-select", "srt-min-words", "srt-max-words", "srt-primary-keyword", "srt-secondary-keywords"].forEach(function (id) {
		var el = document.getElementById(id);
		if (!el) {
			return;
		}
		el.addEventListener("change", function () {
			updateContentPreviewLabels();
			schedulePreviewRefresh();
		});
		el.addEventListener("input", function () {
			schedulePreviewRefresh();
		});
	});

	var refreshButton = document.getElementById("srt-refresh-preview");
	if (refreshButton) {
		refreshButton.addEventListener("click", function () {
			updateContentPreviewLabels();
			fetchContentPreview();
		});
	}

	updateContentPreviewLabels();
	fetchContentPreview();
})();
