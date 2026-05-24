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

	function fetchImagePreview() {
		var routeSelect = document.getElementById("srt-route-select");
		var resultBox = document.getElementById("srt-image-preview-results");
		var promptBox = document.getElementById("srt-image-prompt-preview");
		var config = getSrtAdminConfig();
		if (!routeSelect || !resultBox || !config.restUrl) {
			return;
		}

		resultBox.innerHTML = "<p>Dang tai image preview...</p>";
		fetch(config.restUrl.replace(/\/$/, "") + "/images/generate-preview", {
			method: "POST",
			credentials: "same-origin",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": config.restNonce || ""
			},
			body: JSON.stringify({
				route_id: routeSelect.value,
				image_count: getFieldValue("srt-image-count") || 1,
				image_source_mode: getFieldValue("srt-image-source-mode"),
				image_style: getFieldValue("srt-image-style")
			})
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error("HTTP " + response.status);
				}
				return response.json();
			})
			.then(function (data) {
				var items = data && data.candidates ? data.candidates : [];
				if (promptBox) {
					promptBox.value = data && data.prompt ? data.prompt : "";
				}
				if (!items.length) {
					resultBox.innerHTML = "<p>Khong co image candidate nao.</p>";
					return;
				}
				resultBox.innerHTML = items.map(function (item) {
					var src = item.url || "";
					var label = item.source || "image";
					var caption = item.caption || "";
					if (!src) {
						return "<div class=\"srt-image-preview-item\"><strong>" + label + "</strong><p>" + caption + "</p></div>";
					}
					return "<figure class=\"srt-image-preview-item\"><img src=\"" + src + "\" alt=\"\" style=\"max-width:220px;height:auto;display:block;margin-bottom:8px;\" /><figcaption><strong>" + label + "</strong> " + caption + "</figcaption></figure>";
				}).join("");
			})
			.catch(function (error) {
				resultBox.innerHTML = "<p>Khong tai duoc image preview: " + error.message + "</p>";
			});
	}

	function escapeHtml(value) {
		return String(value || "").replace(/[&<>"]/g, function (ch) {
			return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" })[ch];
		});
	}

	function getSourceResultBox(provider) {
		return document.getElementById("srt-source-result-" + provider);
	}

	function getSourceQuery(provider) {
		var field = document.querySelector('.srt-stock-query[data-provider="' + provider + '"]');
		return field ? (field.value || "").trim() : "";
	}

	function renderSourcePreview(provider, data) {
		var box = getSourceResultBox(provider);
		if (!box) {
			return;
		}
		var images = data && data.images ? data.images : [];
		if (!images.length) {
			box.innerHTML = "<p>Khong co ket qua preview.</p>";
			return;
		}
		box.innerHTML = images.slice(0, 3).map(function (item) {
			var src = item.url || "";
			var caption = item.caption || "";
			var credit = item.credit || "";
			return "<figure style=\"display:inline-block;vertical-align:top;margin:10px 14px 10px 0;max-width:220px;\">" +
				(src ? "<img src=\"" + escapeHtml(src) + "\" alt=\"\" style=\"max-width:220px;height:auto;display:block;margin-bottom:6px;\">" : "") +
				"<figcaption><strong>" + escapeHtml(provider) + "</strong><br>" + escapeHtml(caption) + "<br><small>" + escapeHtml(credit) + "</small></figcaption></figure>";
		}).join("");
	}

	function testImageSource(provider) {
		var config = getSrtAdminConfig();
		var box = getSourceResultBox(provider);
		if (!config.restUrl || !box) {
			return;
		}
		box.innerHTML = "<p>Dang test " + escapeHtml(provider) + "...</p>";
		fetch(config.restUrl.replace(/\/$/, "") + "/images/test-source", {
			method: "POST",
			credentials: "same-origin",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": config.restNonce || ""
			},
			body: JSON.stringify({ provider: provider })
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error("HTTP " + response.status);
				}
				return response.json();
			})
			.then(function (data) {
				var ok = data && data.success;
				var message = ok ? "Ket noi OK." : (data && (data.error || data.message) ? (data.error || data.message) : "Test failed.");
				box.innerHTML = "<p><strong>" + escapeHtml(provider) + ":</strong> " + escapeHtml(message) + "</p>";
				if (ok && data.images) {
					renderSourcePreview(provider, data);
				}
			})
			.catch(function (error) {
				box.innerHTML = "<p>Test source loi: " + escapeHtml(error.message) + "</p>";
			});
	}

	function searchImageSource(provider) {
		var config = getSrtAdminConfig();
		var box = getSourceResultBox(provider);
		if (!config.restUrl || !box) {
			return;
		}
		var query = getSourceQuery(provider) || "mekong delta taxi";
		box.innerHTML = "<p>Dang tim anh tu " + escapeHtml(provider) + "...</p>";
		fetch(config.restUrl.replace(/\/$/, "") + "/images/search-stock", {
			method: "POST",
			credentials: "same-origin",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": config.restNonce || ""
			},
			body: JSON.stringify({
				provider: provider,
				query: query,
				count: 3
			})
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error("HTTP " + response.status);
				}
				return response.json();
			})
			.then(function (data) {
				if (!data || !data.success) {
					box.innerHTML = "<p>Search preview loi: " + escapeHtml((data && (data.error || data.message)) || "Unknown error") + "</p>";
					return;
				}
				renderSourcePreview(provider, data);
			})
			.catch(function (error) {
				box.innerHTML = "<p>Search preview loi: " + escapeHtml(error.message) + "</p>";
			});
	}

	function getAjaxUrl() {
		var config = getSrtAdminConfig();
		return config.ajaxUrl || window.ajaxurl || "";
	}

	function postAdminAjax(action, payload, nonce) {
		var url = getAjaxUrl();
		if (!url) {
			return Promise.reject(new Error("ajax url missing"));
		}
		var body = new URLSearchParams();
		body.append("action", action);
		body.append("nonce", nonce || "");
		Object.keys(payload || {}).forEach(function (key) {
			body.append(key, payload[key]);
		});
		return fetch(url, {
			method: "POST",
			credentials: "same-origin",
			headers: {
				"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
			},
			body: body.toString()
		}).then(function (response) {
			return response.json().then(function (json) {
				if (!response.ok || !json || !json.success) {
					var message = json && json.data && json.data.message ? json.data.message : ("HTTP " + response.status);
					throw new Error(message);
				}
				return json.data || {};
			});
		});
	}

	function parseProviderPayload(value) {
		if (!value) {
			return {};
		}
		try {
			return JSON.parse(value);
		} catch (err) {
			return {};
		}
	}

	function setNotice(el, message, isError) {
		if (!el) {
			return;
		}
		el.style.display = "block";
		el.className = isError ? "notice notice-error inline" : "notice notice-success inline";
		el.innerHTML = "<p>" + escapeHtml(message || "") + "</p>";
	}

	function initAISettingsPage() {
		var root = document.getElementById("srt-ai-settings-page");
		if (!root) {
			return;
		}

		var nonce = root.getAttribute("data-nonce") || (getSrtAdminConfig().adminNonce || "");
		var runtimeNotice = document.getElementById("srt-ai-runtime-test-result");
		var providerBody = document.getElementById("srt-provider-table-body");
		var providerDataNode = document.getElementById("srt-provider-data");
		var modal = document.getElementById("srt-provider-modal");
		var modalTitle = document.getElementById("srt-provider-modal-title");
		var modalForm = document.getElementById("srt-provider-modal-form");
		var modalMessage = document.getElementById("srt-provider-modal-message");
		var providers = [];

		if (!providerBody || !modal || !modalForm) {
			return;
		}

		if (providerDataNode) {
			try {
				providers = JSON.parse(providerDataNode.textContent || "[]");
			} catch (err) {
				providers = [];
			}
		}

		function renderProviders(rows) {
			providers = rows || [];
			if (!providers.length) {
				providerBody.innerHTML = "<tr><td colspan=\"7\">No providers configured yet.</td></tr>";
				return;
			}
			providerBody.innerHTML = providers.map(function (item) {
				var contentModels = item.content_models_preview ? "<div><strong>Content:</strong> " + escapeHtml(item.content_models_preview) + "</div>" : "";
				var imageModels = item.image_models_preview ? "<div><strong>Image:</strong> " + escapeHtml(item.image_models_preview) + "</div>" : "";
				var status = item.last_status || "not_tested";
				var editPayload = escapeHtml(JSON.stringify(item.edit_payload || {}));
				var checkedAt = item.last_checked ? ("<br><small>" + escapeHtml(item.last_checked) + "</small>") : "";
				var statusMessage = item.last_message ? ("<br><small>" + escapeHtml(item.last_message) + "</small>") : "";
				return "<tr data-provider-id=\"" + escapeHtml(item.id || "") + "\">" +
					"<td><strong>" + escapeHtml(item.label || "") + "</strong><br><small>" + escapeHtml(item.api_key_masked || "") + "</small></td>" +
					"<td>" + escapeHtml(item.provider || "") + "</td>" +
					"<td>" + contentModels + imageModels + "</td>" +
					"<td>" + escapeHtml(String(item.priority || 10)) + "</td>" +
					"<td>" + (item.enabled ? "Enabled" : "Disabled") + " - W" + escapeHtml(String(item.weight || 1)) + "</td>" +
					"<td><span class=\"srt-status srt-status-" + escapeHtml(status) + "\">" + escapeHtml(status) + "</span>" + checkedAt + statusMessage + "</td>" +
					"<td>" +
					"<button type=\"button\" class=\"button button-small srt-provider-edit\" data-provider=\"" + editPayload + "\">Edit</button> " +
					"<button type=\"button\" class=\"button button-small srt-provider-test\" data-provider-id=\"" + escapeHtml(item.id || "") + "\">Test</button> " +
					"<button type=\"button\" class=\"button button-small srt-provider-delete\" data-provider-id=\"" + escapeHtml(item.id || "") + "\">Delete</button>" +
					"</td>" +
					"</tr>";
			}).join("");
		}

		function openModal(editPayload, mode) {
			var data = editPayload || {};
			modalForm.reset();
			document.getElementById("srt-provider-id").value = data.id || "";
			document.getElementById("srt-provider-enabled").checked = !data.id || String(data.enabled) === "1" || data.enabled === 1 || data.enabled === true;
			document.getElementById("srt-provider-label").value = data.label || "";
			document.getElementById("srt-provider-type").value = data.provider || "shopaikey_compatible";
			document.getElementById("srt-provider-base-url").value = data.base_url || "";
			document.getElementById("srt-provider-api-key").value = "";
			document.getElementById("srt-provider-content-models").value = data.content_models || "";
			document.getElementById("srt-provider-image-models").value = data.image_models || "";
			document.getElementById("srt-provider-image-endpoint").value = data.image_endpoint || "/images/generations";
			document.getElementById("srt-provider-image-edit-endpoint").value = data.image_edit_endpoint || "/images/edits";
			document.getElementById("srt-provider-image-api-format").value = data.image_api_format || "openai_images";
			document.getElementById("srt-provider-priority").value = data.priority || 10;
			document.getElementById("srt-provider-weight").value = data.weight || 1;
			modalTitle.textContent = mode === "edit" ? "Edit Provider" : "Add Provider";
			modalMessage.style.display = "none";
			modal.hidden = false;
		}

		function closeModal() {
			modal.hidden = true;
			modalMessage.style.display = "none";
		}

		function providerPayloadFromForm() {
			return {
				"provider[id]": document.getElementById("srt-provider-id").value || "",
				"provider[label]": document.getElementById("srt-provider-label").value || "",
				"provider[provider]": document.getElementById("srt-provider-type").value || "shopaikey_compatible",
				"provider[base_url]": document.getElementById("srt-provider-base-url").value || "",
				"provider[api_key]": document.getElementById("srt-provider-api-key").value || "",
				"provider[content_models]": document.getElementById("srt-provider-content-models").value || "",
				"provider[image_models]": document.getElementById("srt-provider-image-models").value || "",
				"provider[image_endpoint]": document.getElementById("srt-provider-image-endpoint").value || "/images/generations",
				"provider[image_edit_endpoint]": document.getElementById("srt-provider-image-edit-endpoint").value || "/images/edits",
				"provider[image_api_format]": document.getElementById("srt-provider-image-api-format").value || "openai_images",
				"provider[priority]": document.getElementById("srt-provider-priority").value || "10",
				"provider[weight]": document.getElementById("srt-provider-weight").value || "1",
				"provider[enabled]": document.getElementById("srt-provider-enabled").checked ? "1" : "0"
			};
		}

		document.getElementById("srt-provider-add").addEventListener("click", function () {
			openModal({}, "add");
		});
		document.getElementById("srt-provider-cancel").addEventListener("click", closeModal);
		document.getElementById("srt-provider-modal-close").addEventListener("click", closeModal);

		modalForm.addEventListener("submit", function (ev) {
			ev.preventDefault();
			postAdminAjax("srt_ai_upsert_provider", providerPayloadFromForm(), nonce)
				.then(function (data) {
					setNotice(modalMessage, data.message || "Provider saved.", false);
					renderProviders(data.providers || []);
					window.setTimeout(closeModal, 280);
				})
				.catch(function (error) {
					setNotice(modalMessage, error.message || "Unable to save provider.", true);
				});
		});

		providerBody.addEventListener("click", function (ev) {
			var editButton = ev.target.closest(".srt-provider-edit");
			if (editButton) {
				openModal(parseProviderPayload(editButton.getAttribute("data-provider")), "edit");
				return;
			}
			var testButton = ev.target.closest(".srt-provider-test");
			if (testButton) {
				var testId = testButton.getAttribute("data-provider-id") || "";
				postAdminAjax("srt_ai_test_provider", { provider_id: testId }, nonce)
					.then(function (data) {
						renderProviders(data.providers || []);
						setNotice(runtimeNotice, data.message || "Provider tested.", !(data.success));
					})
					.catch(function (error) {
						setNotice(runtimeNotice, error.message || "Provider test failed.", true);
					});
				return;
			}
			var deleteButton = ev.target.closest(".srt-provider-delete");
			if (deleteButton) {
				var deleteId = deleteButton.getAttribute("data-provider-id") || "";
				if (!window.confirm("Delete this provider?")) {
					return;
				}
				postAdminAjax("srt_ai_delete_provider", { provider_id: deleteId }, nonce)
					.then(function (data) {
						renderProviders(data.providers || []);
						setNotice(runtimeNotice, data.message || "Provider deleted.", false);
					})
					.catch(function (error) {
						setNotice(runtimeNotice, error.message || "Delete failed.", true);
					});
			}
		});

		document.getElementById("srt-test-runtime-active").addEventListener("click", function () {
			postAdminAjax("srt_ai_test_runtime", { mode: "active" }, nonce)
				.then(function (data) {
					renderProviders(data.providers || []);
					setNotice(runtimeNotice, data.message || "Runtime test complete.", !(data.success));
				})
				.catch(function (error) {
					setNotice(runtimeNotice, error.message || "Runtime test failed.", true);
				});
		});

		document.getElementById("srt-test-runtime-all").addEventListener("click", function () {
			postAdminAjax("srt_ai_test_runtime", { mode: "all" }, nonce)
				.then(function (data) {
					renderProviders(data.providers || []);
					setNotice(runtimeNotice, data.message || "All providers tested.", !(data.success));
				})
				.catch(function (error) {
					setNotice(runtimeNotice, error.message || "Bulk provider test failed.", true);
				});
		});

		renderProviders(providers);
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

	var imagePreviewButton = document.getElementById("srt-generate-image-preview");
	if (imagePreviewButton) {
		imagePreviewButton.addEventListener("click", function () {
			fetchImagePreview();
		});
	}

	document.querySelectorAll(".srt-test-image-source").forEach(function (button) {
		button.addEventListener("click", function () {
			testImageSource(button.getAttribute("data-provider") || "");
		});
	});

	document.querySelectorAll(".srt-search-image-source").forEach(function (button) {
		button.addEventListener("click", function () {
			searchImageSource(button.getAttribute("data-provider") || "");
		});
	});

	updateContentPreviewLabels();
	fetchContentPreview();
	initAISettingsPage();
})();
