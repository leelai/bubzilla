<div class="mb-3 field custom">
<label for="group-selection" id="group-selection-lbl">{{$label}}</label>
<select class="form-control" name="group-selection" id="group-selection" >
{{foreach $groups as $group}}
<option value="{{$group.id}}" {{if $group.selected}}selected="selected"{{/if}} >{{$group.name}}</option>
{{/foreach}}
</select>
</div>
