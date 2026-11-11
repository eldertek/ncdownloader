<template>
    <div class="system-info-wrapper section">
        <h2 class="section-title">System Info</h2>
        <div class="system-info">
            <div class="system-info-item">
                <div class="system-info-item-label">Aria2 Version: </div>
                <div class="system-info-item-value"><span class="version">{{ aria2Ver }}</span></div>
            </div>
            <div class="system-info-item">
                <div class="system-info-item-label">yt-dlp Version: </div>
                <div class="system-info-item-value">
                    <span class="version">{{ ytdVer }}</span>
                    <actionButton :action="ytdAction" btnType="ytd" @clicked="checkUpdate" enableLoading="true"
                        className="check-button">
                        {{ ytdBtn }}
                    </actionButton>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import helper from "../utils/helper";
import actionButton from "./actionButton";

const ARIA2_CHECK_URL = "/apps/ncdownloader/aria2/release/check";
const ARIA2_UPDATE_URL = "/apps/ncdownloader/aria2/release/update";
const YTD_CHECK_URL = "/apps/ncdownloader/ytdl/release/check";
const YTD_UPDATE_URL = "/apps/ncdownloader/ytdl/release/update";

export default {
    name: "systemInfo",
    data() {
        return {
            ytdBtn: "Check for Update",
            ytdAction: "check",
            overrideAria2Ver: null,
            overrideYtdVer: null,
        };
    },
    components: {
        actionButton
    },
    computed: {
        aria2Ver() {
            return this.overrideAria2Ver !== null ? this.overrideAria2Ver : this.aria2Version;
        },
        ytdVer() {
            return this.overrideYtdVer !== null ? this.overrideYtdVer : this.ytdVersion;
        }
    },
    methods: {
        checkUpdate(_event, $vm) {
            const { btnType } = $vm.$props;
            const action = this.ytdAction;
            const path = action === "check"
                ? (btnType === "aria2" ? ARIA2_CHECK_URL : YTD_CHECK_URL)
                : (btnType === "aria2" ? ARIA2_UPDATE_URL : YTD_UPDATE_URL);
            helper
                .httpClient(helper.generateUrl(path))
                .setMethod("GET")
                .setHandler((data) => {
                    $vm.loading = false;
                    if (data && data.status) {
                        helper.info(data.message);
                        if (action === "check") {
                            this.ytdBtn = "Update";
                            this.ytdAction = "update";
                        } else {
                            this.ytdBtn = "Check for Update";
                            this.ytdAction = "check";
                            if (data.data) {
                                if (btnType === "ytd") {
                                    this.overrideYtdVer = data.data;
                                } else if (btnType === "aria2") {
                                    this.overrideAria2Ver = data.data;
                                }
                            }
                        }
                    } else if (data && data.message) {
                        helper.info(data.message);
                    }
                })
                .send();
        },
    },
    props: {
        aria2Version: {
            type: String,
            default: ""
        },
        ytdVersion: {
            type: String,
            default: ""
        },
    },
};
</script>
<style scoped lang="scss">
.system-info {
    display: flex;
    flex-direction: column;
    margin-top: 10px;

    .system-info-item {
        display: flex;
        flex-direction: row;
        margin-bottom: 10px;
    }

    .system-info-item-label {
        font-weight: bold;
        margin-right: 10px;
        display: flex;
        align-items: flex-end;
    }

    .system-info-item-value {
        font-weight: normal;

        .check-button {
            border-radius: 0.25em;
        }
    }
}
</style>
