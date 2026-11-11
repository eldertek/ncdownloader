<template>
  <section id="ncdownloader-settings-collapsible-container">
    <div class="ncdownloader-settings-item" :data-tippy-content="errorTooltip">
      <toggleButton
        :disabledText="errorText"
        :enabledText="errorText"
        :defaultStatus="toggleStatus"
        @changed="toggle"
        name="ncd_hide_errors"
      ></toggleButton>
    </div>
    <div class="ncdownloader-settings-item" :data-tippy-content="btTooltip">
      <toggleButton
        v-if="isAdmin"
        disabledText="Disable BT for non-admin users"
        enabledText="Disable BT for non-admin users"
        :defaultStatus="btStatus"
        name="ncd_disable_bt"
        @changed="toggle"
      ></toggleButton>
    </div>
    <div class="ncdownloader-settings-item">
      <a :href="personal.url" title="">
        <button type="button">{{ personal.title }}</button>
      </a>
    </div>
    <div class="ncdownloader-settings-item" v-if="isAdmin">
      <a :href="admin.url" :title="admin.title">
        <button type="button">{{ admin.title }}</button>
      </a>
    </div>
  </section>
</template>

<script>
import toggleButton from "./components/toggleButton";
import helper from "./utils/helper";
import { translate as t } from "@nextcloud/l10n";

const basePath = "/apps/ncdownloader";

export default {
  name: "settingsBar",
  inject: ["settings"],
  data() {
    const cfg = this.settings || {};
    return {
      personal: {
        title: t("ncdownloader", "Personal Settings"),
        url: cfg.personal_url || "#",
      },
      admin: {
        title: t("ncdownloader", "Admin Settings"),
        url: cfg.admin_url || "#",
      },
      isAdmin: Boolean(cfg.is_admin),
      sectionName: t("ncdownloader", "Settings"),
      errorText: t("ncdownloader", "Hide Errors"),
      toggleStatus: helper.str2Boolean(String(cfg.ncd_hide_errors || "")),
      btStatus: helper.str2Boolean(String(cfg.ncd_disable_bt || "")),
      errorTooltip: t("ncdownloader", "Enable this to hide errors"),
      btTooltip: t("ncdownloader", "Disable BT for non-admin users"),
    };
  },
  methods: {
    toggle(name, value) {
      const data = {};
      data[name] = value ? 1 : 0;
      const path = name === "ncd_disable_bt" ? "/admin/save" : "/personal/save";
      const url = helper.generateUrl(basePath + path);
      helper.httpClient(url)
        .setData(data)
        .setHandler((resp) => {
          if (resp && resp.message) {
            helper.message(t("ncdownloader", resp.message), 1000);
          }
        })
        .send();
    },
  },
  components: {
    toggleButton,
  },
};
</script>

<style lang="scss">
@import "css/variables.scss";
#ncdownloader-settings-collapsible-container {
  display: flex;
  flex-flow: column wrap;
}
</style>
