<template>
    <transition name="modal">
        <div class="territory-popover" v-if="data"
             :style="{'top': data.y + 'px', 'left': data.x + 'px'}">
            <div class="popover-title" :style="getTitleColor(data.territory.occupation)">
                <img v-if="data.territory.occupation && !data.territory.occupation.user.house" :src="getAvatar(data.territory.occupation)"/>
                <span :style="data.territory.occupation ? '' : 'color: #1D1F21; text-shadow: none;'">
                    {{getTitle(data.territory.occupation)}}
                </span>
                <div :style="data.territory.occupation ? '' : 'color: #1D1F21; text-shadow: none;'" class="close-popover" @click="closePopover">X</div>
            </div>

            <div class="popover-subtitle" v-if="data.territory.occupation && data.territory.occupation.user.house"
                 :style="{'background-color': data.territory.occupation.user.color}">
                <img v-if="data.territory.occupation" :src="getAvatar(data.territory.occupation)"/>
                <span :style="data.territory.occupation ? '' : 'color: #1D1F21; text-shadow: none;'">
                    {{data.territory.occupation.user.name}}
                </span>
            </div>

            <div class="popover-content">
                <u>Territory {{data.territory.id}}</u>
                <div v-if="data.territory.occupation">Captured: <b>{{formatDate(data.territory.occupation.api_created_at)}}</b></div>

                <div v-if="data.territory.occupation && data.territory.occupation.previous_occupation">Previous occupant: <b
                        :style="{color: data.territory.occupation.previous_occupation.user.color}">
                    {{data.territory.occupation.previous_occupation.user.name}}</b>
                </div>
            </div>
        </div>
    </transition>
</template>

<script>
    import Helpers from '../helpers';

    export default {
        props: [
            'data'
        ],

        methods: {
            formatDate: function(date) {
                return Helpers.formatDate(date);
            },

            closePopover: function() {
                Bus.$emit('close-popover');
            },

            getTitleColor: function(occupation) {
                let color = '#FFFFFF';

                if (occupation) {
                    color = occupation.user.house ? occupation.user.house.color : occupation.user.color;
                }

                return {'background-color': color};
            },

            getTitle: function(occupation) {
                let title = 'Unoccupied';

                if (occupation) {
                    title = occupation.user.house ? occupation.user.house.name : occupation.user.name;
                }

                return title;
            },

            getAvatar: function(occupation) {
                return occupation && occupation.user.image ? occupation.user.image : '/images/default_avatar.png';
            },
        }
    }
</script>