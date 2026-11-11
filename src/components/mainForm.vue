<template>
  <form class="main-form" id="nc-vue-unified-form" :action="path">
    <div class="options-group">
      <div
        class="magnet-link http-link option-buttons active-button"
        @click.prevent="whichType('aria2', $event)"
      >
        {{ httpLabel }}
      </div>
      <div
        class="ytdl-link option-buttons"
        @click.prevent="whichType('ytdl', $event)"
      >
        {{ ytdlLabel }}
      </div>
      <div
        class="search-torrents option-buttons"
        @click.prevent="whichType('search', $event)"
      >
        {{ searchLabel }}
      </div>
    </div>
    <div class="action-group">
      <div class="download-input-container" v-if="inputType === 'download'">
        <textInput :placeholder="placeholder" :dataType="downloadType"></textInput>
        <div class="download-controls-container">
          <div v-if="checkboxes" id="select-value-extension-container">
            <select :value="selectedExt" id="select-value-extension">
              <option id="defaultext" value="defaultext">{{ defaultLabel }}</option>
              <optgroup :label="videoLabel">
                <option id="mp4" value="mp4">mp4</option>
                <option id="webm" value="webm">webm</option>
                <option id="mkv" value="mkv">mkv</option>
              </optgroup>
              <optgroup :label="audioLabel">
                <option id="m4a" value="m4a">m4a</option>
                <option id="mp3" value="mp3">mp3</option>
                <option id="opus" value="opus">opus</option>
                <option id="vorbis" value="vorbis">vorbis</option>
              </optgroup>
            </select>
          </div>
          <actionButton className="download-button" @clicked="download"></actionButton>
          <uploadFile
            v-if="downloadType === 'aria2'"
            @uploadfile="uploadFile"
            :path="uris.upload_url"
          ></uploadFile>
          <folderSettings :path="dlPath"></folderSettings>
        </div>
      </div>
      <searchInput
        v-else
        @search="search"
        @optionSelected="optionCallback"
        :selectOptions="searchOptions"
      ></searchInput>
    </div>
  </form>
</template>
<script>
import textInput from "./textInput";
import searchInput from "./searchInput.vue";
import actionButton from "./actionButton";
import uploadFile from "./uploadFile";
import { translate as t } from "@nextcloud/l10n";
import folderSettings from "./folderSettings.vue";

export default {
  inject: ["settings", "search_sites"],
  data() {
    const cfg = (this.settings && this.settings.settings) || {};
    return {
      checkedValue: false,
      path: this.uris.aria2_url,
      dlPath: cfg.ncd_downloader_dir || "/Downloads",
      inputType: "download",
      checkboxes: false,
      downloadType: "aria2",
      httpLabel: t("ncdownloader", "HTTP/MAGNET"),
      ytdlLabel: t("ncdownloader", "yt-dlp"),
      defaultLabel: t("ncdownloader", "Default"),
      videoLabel: t("ncdownloader", "Video"),
      audioLabel: t("ncdownloader", "Audio"),
      placeholder: t("ncdownloader", "Paste your http/magnet link here"),
      searchLabel: t("ncdownloader", "Search Torrents"),
      searchOptions: this.search_sites && this.search_sites.length ? this.search_sites : this.noOptions(),
      selectedExt: "defaultext",
    };
  },
  components: {
    textInput,
    actionButton,
    searchInput,
    uploadFile,
    folderSettings,
  },
  methods: {
    whichType(type, event) {
      const element = event.currentTarget || event.target;
      const nodeList = document.querySelectorAll(".option-buttons");
      nodeList.forEach((node) => {
        node.classList.remove("active-button");
      });
      element.classList.add("active-button");
      this.downloadType = type;
      if (type === "aria2") {
        this.placeholder = t("ncdownloader", "Paste your http/magnet link here");
        this.path = this.uris.aria2_url;
      } else if (type === "ytdl") {
        this.placeholder = t("ncdownloader", "Paste your video link here");
        this.path = this.uris.ytd_url;
      } else {
        this.path = this.uris.search_url;
      }
      this.checkboxes = type === "ytdl";
      this.inputType = type === "search" ? "search" : "download";
    },
    download(event) {
      this.$emit("download", event);
    },
    search(event, vm) {
      this.$emit("search", event, vm);
    },
    uploadFile(event, vm) {
      this.$emit("uploadfile", event, vm);
    },
    optionCallback(option) {
      if (option && option.label && option.label.toLowerCase() === "music") {
        this.searchLabel = t("ncdownloader", "Search Music");
      } else {
        this.searchLabel = t("ncdownloader", "Search Torrents");
      }
    },
    noOptions() {
      return [{ name: "nooptions", label: "No Options" }];
    },
  },
  name: "mainForm",
  props: {
    uris: Object,
    uri: String,
  },
};
</script>
<style lang="scss">
@import "../css/variables.scss";

#nc-vue-unified-form {
  display: flex;
  width: 100%;
  min-height: $column-height;
  font-size: medium;

  .action-group {
    width: 100%;
    flex: 2;
  }

  .options-group,
  .action-group > div {
    display: flex;
    width: auto;
    min-height: $column-height;
    position: relative;
  }

  .options-group > .option-buttons {
    margin: 0;
    padding: 10px 14px;
    outline: 0;
    font-weight: 600;
    font-size: 13px;
    font-family: inherit;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    white-space: nowrap;
    min-height: 40px;
    background-color: var(--color-background-dark, #e5e7eb);
    color: var(--color-main-text, #181616);
    border: 1px solid var(--color-border, #cbd5e1);
    transition: background-color 0.15s ease;
  }

  .options-group > .option-buttons:first-child {
    border-radius: 10px 0 0 10px;
  }

  .options-group > .option-buttons.active-button {
    background-color: var(--color-primary-element, #00679e);
    color: var(--color-primary-element-text, #ffffff);
    border-color: var(--color-primary-element, #00679e);
  }

  .action-group > div {
    border: 1px solid var(--color-border-dark, #94a3b8);
    border-radius: 0 10px 10px 0;
    overflow: hidden;

    & > div,
    & > select {
      display: flex;
      padding: 0;
      margin: 0;
    }

    & > div[class$="-controls-container"] {
      display: flex;
      align-items: stretch;

      & div,
      & select {
        color: var(--color-main-text, #181616);
        font-size: medium;
        background-color: var(--color-background-hover, #f1f5f9);
      }
    }
  }

  .download-button.btn-primary,
  .search-button.btn-primary {
    color: var(--color-primary-element-text, #ffffff);
    background-color: var(--color-primary-element, #00679e);
    border-color: var(--color-primary-element, #00679e);
    border-radius: 0;
  }

  .download-button.btn-primary:hover,
  .search-button.btn-primary:hover {
    filter: brightness(0.92);
  }

  input,
  select,
  button {
    margin: 0;
    border: 0;
    padding: 8px 12px;
    min-height: 40px;
  }

  button {
    white-space: nowrap;
  }
}

@media only screen and (max-width: 1024px) {
  #nc-vue-unified-form {
    flex-flow: column;
    row-gap: 8px;
    height: auto;

    .options-group {
      width: 100%;

      & > .option-buttons {
        flex: 1 1 0;
        text-align: center;
      }

      & > .option-buttons:first-child {
        border-radius: 8px 0 0 8px;
      }

      & > .option-buttons:last-child {
        border-radius: 0 8px 8px 0;
      }
    }

    .action-group > div {
      width: 100%;
      border-radius: 8px;
      flex-flow: column nowrap;

      & > div[class$="-controls-container"] {
        display: flex;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 4px;
      }
    }
  }
}
</style>
