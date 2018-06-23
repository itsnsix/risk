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
                        <ol>
                            <li>Post art as usual</li>
                            <li>Gain territory automatically</li>
                            <li>???</li>
                            <li>Profit</li>
                        </ol>
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