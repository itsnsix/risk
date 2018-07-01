<template>
    <transition name="modal">
        <div class="modal-mask" v-if="show">
            <div class="modal-wrapper">
                <div class="modal-container">

                    <div class="modalheader">
                        <div>Whats and Hows</div>
                        <div class="close-modal" @click="close">X</div>
                    </div>

                    <div class="modalbody">
                        <div class="info-section">
                            <ol>
                                <li>Post art as usual</li>
                                <li>Gain territory automatically</li>
                                <li>???</li>
                                <li>Profit</li>
                            </ol>
                        </div>

                        <div class="info-section">
                            The map updates every 30 minutes. If you can't wait that long, click <a target="_blank" href="/import">here</a> to trigger an update.
                        </div>

                        <div class="info-section">
                            Your army only has enough strength to expand to a maximum of 3 new territories a day.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </transition>
</template>

<script>
    export default {
        data() {
            return {
                show: false
            };
        },

        mounted() {
            Bus.$on('toggle-help-modal', () => {
                this.show = !this.show;
            });
        },

        methods: {
            close: function() {
                Bus.$emit('toggle-help-modal');
            }
        }
    }
</script>